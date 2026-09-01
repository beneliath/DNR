package main

import (
	"encoding/json"
	"fmt"
	"net/http"
	"sync"
	"time"

	"github.com/mattermost/mattermost/server/public/model"
	"github.com/mattermost/mattermost/server/public/plugin"
	"github.com/mattermost/mattermost/server/public/pluginapi"
)

const pluginID = "org.moed.mattermost"

type Plugin struct {
	plugin.MattermostPlugin

	configurationLock sync.RWMutex
	configuration     *configuration
	client            *pluginapi.Client
	botID             string
	router            *http.ServeMux
	replyPollStop     chan struct{}
	replyPollDone     chan struct{}
}

func (p *Plugin) OnActivate() error {
	p.client = pluginapi.NewClient(p.API, p.Driver)
	botID, err := p.client.Bot.EnsureBot(&model.Bot{
		Username:    "moed",
		DisplayName: "MOED",
		Description: "MOED engagement workflow assistant",
	})
	if err != nil {
		return fmt.Errorf("ensure MOED bot: %w", err)
	}
	p.botID = botID

	if appErr := p.API.RegisterCommand(&model.Command{
		Trigger:          "moed",
		AutoComplete:     true,
		AutoCompleteDesc: "MOED engagement summaries and follow-up actions",
		AutoCompleteHint: "[help|status|connect|today|tasks|event|link-event|unlink-event]",
	}); appErr != nil {
		return fmt.Errorf("register /moed command: %s", appErr.Error())
	}
	p.reconcileChannelBindingMarkers()

	router := http.NewServeMux()
	router.HandleFunc("/api/v1/task-action", p.handleTaskAction)
	router.HandleFunc("/api/v1/web/task-action", p.handleWebTaskAction)
	router.HandleFunc("/api/v1/post-action", p.handlePostAction)
	router.HandleFunc("/api/v1/channel-binding", p.handleChannelBinding)
	router.HandleFunc("/api/v1/email-compose", p.handleEmailCompose)
	router.HandleFunc("/api/v1/email-send", p.handleEmailSend)
	router.HandleFunc("/api/v1/email-status", p.handleEmailStatus)
	p.router = router
	p.replyPollStop = make(chan struct{})
	p.replyPollDone = make(chan struct{})
	go p.runReplyNotificationPoller(p.replyPollStop, p.replyPollDone)
	return nil
}

func (p *Plugin) OnDeactivate() error {
	if p.replyPollStop == nil || p.replyPollDone == nil {
		return nil
	}
	close(p.replyPollStop)
	select {
	case <-p.replyPollDone:
	case <-time.After(5 * time.Second):
		return fmt.Errorf("reply notification poller did not stop")
	}
	return nil
}

func (p *Plugin) OnConfigurationChange() error {
	var next configuration
	if err := p.API.LoadPluginConfiguration(&next); err != nil {
		return fmt.Errorf("load plugin configuration: %w", err)
	}
	if err := next.validate(); err != nil {
		return err
	}
	p.configurationLock.Lock()
	p.configuration = &next
	p.configurationLock.Unlock()
	return nil
}

func (p *Plugin) getConfiguration() *configuration {
	p.configurationLock.RLock()
	defer p.configurationLock.RUnlock()
	if p.configuration == nil {
		return nil
	}
	clone := *p.configuration
	return &clone
}

func (p *Plugin) ServeHTTP(_ *plugin.Context, writer http.ResponseWriter, request *http.Request) {
	writer.Header().Set("Cache-Control", "no-store")
	writer.Header().Set("X-Content-Type-Options", "nosniff")
	if p.router == nil {
		http.Error(writer, "plugin is not ready", http.StatusServiceUnavailable)
		return
	}
	p.router.ServeHTTP(writer, request)
}

func (p *Plugin) apiClient() (*moedClient, error) {
	config := p.getConfiguration()
	if config == nil {
		return nil, fmt.Errorf("the plugin has not been configured")
	}
	return newMoedClient(config), nil
}

func (p *Plugin) mattermostUser(userID string) (*model.User, error) {
	user, appErr := p.API.GetUser(userID)
	if appErr != nil {
		return nil, fmt.Errorf("look up Mattermost user: %s", appErr.Error())
	}
	return user, nil
}

func (p *Plugin) setChannelBinding(channelID string, binding *channelBinding) error {
	key := "channel_binding:" + channelID
	if binding == nil {
		if appErr := p.API.KVDelete(key); appErr != nil {
			return fmt.Errorf("remove channel binding: %s", appErr.Error())
		}
		return nil
	}
	encoded, err := json.Marshal(binding)
	if err != nil {
		return fmt.Errorf("encode channel binding: %w", err)
	}
	if appErr := p.API.KVSet(key, encoded); appErr != nil {
		return fmt.Errorf("store channel binding: %s", appErr.Error())
	}
	return nil
}

func (p *Plugin) channelBinding(channelID string) (*channelBinding, error) {
	encoded, appErr := p.API.KVGet("channel_binding:" + channelID)
	if appErr != nil {
		return nil, fmt.Errorf("read channel binding: %s", appErr.Error())
	}
	if len(encoded) == 0 {
		return nil, nil
	}
	var binding channelBinding
	if err := json.Unmarshal(encoded, &binding); err != nil {
		return nil, fmt.Errorf("decode channel binding: %w", err)
	}
	return &binding, nil
}
