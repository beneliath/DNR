package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/mattermost/mattermost/server/public/model"
	"github.com/mattermost/mattermost/server/public/plugin"
)

func ephemeral(text string) *model.CommandResponse {
	return &model.CommandResponse{ResponseType: model.CommandResponseTypeEphemeral, Text: text}
}

const (
	postTypeToday = "custom_moed_today"
	postTypeTasks = "custom_moed_tasks"
	postTypeEvent = "custom_moed_event"
)

func customCommandResponse(responseType, postType, text string, payload any) *model.CommandResponse {
	payloadJSON := "{}"
	if encoded, err := json.Marshal(payload); err == nil {
		payloadJSON = string(encoded)
	}
	return &model.CommandResponse{
		ResponseType: responseType,
		Text:         text,
		Type:         postType,
		Props: model.StringInterface{
			"moed": payloadJSON,
			"type": postType,
		},
	}
}

func (p *Plugin) sendCustomEphemeral(userID, channelID string, response *model.CommandResponse) *model.CommandResponse {
	post := p.API.SendEphemeralPost(userID, &model.Post{
		UserId:    p.botID,
		ChannelId: channelID,
		Message:   response.Text,
		Type:      response.Type,
		Props:     response.Props,
	})
	if post == nil {
		return response
	}
	return &model.CommandResponse{}
}

func commandError(err error, linkURL string) *model.CommandResponse {
	var apiErr *moedAPIError
	if errors.As(err, &apiErr) {
		if apiErr.Payload.Code == "account_not_linked" {
			return ephemeral("Your Mattermost account is not linked to MOED. [Generate a one-time code](" + linkURL + ") and run `/moed connect CODE`.")
		}
		return ephemeral(":warning: " + escapeMarkdown(apiErr.Error()))
	}
	return ephemeral(":warning: MOED is temporarily unavailable. Ask an administrator to check the plugin connection.")
}

func (p *Plugin) helpResponse() *model.CommandResponse {
	config := p.getConfiguration()
	linkURL := ""
	if config != nil {
		linkURL = config.MoedURL + "/mattermost.php"
	}
	text := "### MOED commands\n" +
		"- `/moed status` — check the connection and linked account\n" +
		"- `/moed connect CODE` — link using a one-time code from [MOED](" + linkURL + ")\n" +
		"- `/moed today` — your tasks and upcoming engagements\n" +
		"- `/moed tasks` — your active assigned tasks\n" +
		"- `/moed event search TEXT` — find an engagement\n" +
		"- `/moed event show ID` — show a share-safe engagement card\n" +
		"- `/moed link-event ID` — bind this channel (Editor/Admin)\n" +
		"- `/moed unlink-event` — remove the channel binding (Editor/Admin)"
	return ephemeral(text)
}

func (p *Plugin) ExecuteCommand(_ *plugin.Context, args *model.CommandArgs) (*model.CommandResponse, *model.AppError) {
	config := p.getConfiguration()
	if config == nil {
		return ephemeral(":warning: The MOED plugin is not configured."), nil
	}
	client, err := p.apiClient()
	if err != nil {
		return ephemeral(":warning: " + escapeMarkdown(err.Error())), nil
	}
	user, err := p.mattermostUser(args.UserId)
	if err != nil {
		return ephemeral(":warning: Mattermost could not resolve your user account."), nil
	}
	ctx, cancel := context.WithTimeout(context.Background(), config.timeout())
	defer cancel()

	input := strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(args.Command), "/moed"))
	if input == "" {
		binding, bindingErr := p.channelBinding(args.ChannelId)
		if bindingErr != nil || binding == nil {
			return p.helpResponse(), nil
		}
		response, apiErr := client.event(ctx, user.Id, user.Username, binding.EngagementID)
		if apiErr != nil {
			return commandError(apiErr, config.MoedURL+"/mattermost.php"), nil
		}
		return p.sendCustomEphemeral(args.UserId, args.ChannelId, customCommandResponse(
			model.CommandResponseTypeEphemeral,
			postTypeEvent,
			"This channel is linked to MOED engagement **#"+strconv.Itoa(binding.EngagementID)+"**.",
			response.Engagement,
		)), nil
	}

	parts := strings.Fields(input)
	switch parts[0] {
	case "help":
		return p.helpResponse(), nil
	case "status":
		status, statusErr := client.status(ctx)
		if statusErr != nil {
			return commandError(statusErr, config.MoedURL+"/mattermost.php"), nil
		}
		me, meErr := client.me(ctx, user.Id, user.Username)
		if meErr != nil {
			var apiErr *moedAPIError
			if errors.As(meErr, &apiErr) && apiErr.Payload.Code == "account_not_linked" {
				return ephemeral(":white_check_mark: Connected to **" + escapeMarkdown(status.Application) + "**. Your account is not linked yet. [Generate a code](" + config.MoedURL + "/mattermost.php)."), nil
			}
			return commandError(meErr, config.MoedURL+"/mattermost.php"), nil
		}
		return ephemeral(fmt.Sprintf(
			":white_check_mark: Connected to **%s** as **%s** (`%s`).",
			escapeMarkdown(status.Application),
			escapeMarkdown(me.User.DisplayName),
			escapeMarkdown(me.User.Role),
		)), nil
	case "connect":
		if len(parts) != 2 {
			return ephemeral("Usage: `/moed connect CODE`"), nil
		}
		response, connectErr := client.connect(ctx, user.Id, user.Username, parts[1])
		if connectErr != nil {
			return commandError(connectErr, config.MoedURL+"/mattermost.php"), nil
		}
		return ephemeral(":white_check_mark: " + escapeMarkdown(response.Message) + " Signed in as **" + escapeMarkdown(response.User.Username) + "**."), nil
	case "today":
		response, todayErr := client.today(ctx, user.Id, user.Username)
		if todayErr != nil {
			return commandError(todayErr, config.MoedURL+"/mattermost.php"), nil
		}
		return p.sendCustomEphemeral(args.UserId, args.ChannelId, todayCommandResponse(response)), nil
	case "tasks":
		response, tasksErr := client.tasks(ctx, user.Id, user.Username)
		if tasksErr != nil {
			return commandError(tasksErr, config.MoedURL+"/mattermost.php"), nil
		}
		return p.sendCustomEphemeral(args.UserId, args.ChannelId, tasksCommandResponse(response)), nil
	case "event":
		return p.executeEventCommand(ctx, client, user, config, args.ChannelId, input), nil
	case "link-event":
		return p.executeLinkEvent(ctx, client, user, config, args.ChannelId, input), nil
	case "unlink-event":
		return p.executeUnlinkEvent(ctx, client, user, config, args.ChannelId), nil
	default:
		return p.helpResponse(), nil
	}
}

func todayCommandResponse(response *todayResponse) *model.CommandResponse {
	lines := []string{"### Today in MOED · " + escapeMarkdown(response.BusinessDate)}
	if len(response.Engagements) == 0 {
		lines = append(lines, "No upcoming engagements.")
	} else {
		lines = append(lines, "**Upcoming engagements**")
		for _, event := range response.Engagements {
			lines = append(lines, fmt.Sprintf(
				"- [%s](%s) · %s",
				escapeMarkdown(event.Title),
				event.URL,
				formatDateRange(event.EventStartDate, event.EventEndDate),
			))
		}
	}
	if len(response.Tasks) == 0 {
		lines = append(lines, "\n:white_check_mark: You have no active assigned tasks.")
	} else {
		lines = append(lines, fmt.Sprintf("\n**Your active tasks (%d)**", len(response.Tasks)))
	}
	return customCommandResponse(
		model.CommandResponseTypeEphemeral,
		postTypeToday,
		strings.Join(lines, "\n"),
		response,
	)
}

func tasksCommandResponse(response *todayResponse) *model.CommandResponse {
	text := "### My MOED tasks"
	if len(response.Tasks) == 0 {
		text += "\n:white_check_mark: You have no active assigned tasks."
	}
	return customCommandResponse(
		model.CommandResponseTypeEphemeral,
		postTypeTasks,
		text,
		response,
	)
}

func (p *Plugin) executeEventCommand(
	ctx context.Context,
	client *moedClient,
	user *model.User,
	config *configuration,
	channelID string,
	input string,
) *model.CommandResponse {
	if strings.HasPrefix(input, "event search ") {
		query := strings.TrimSpace(strings.TrimPrefix(input, "event search "))
		response, err := client.searchEvents(ctx, user.Id, user.Username, query)
		if err != nil {
			return commandError(err, config.MoedURL+"/mattermost.php")
		}
		if len(response.Engagements) == 0 {
			return ephemeral("No MOED engagements matched **" + escapeMarkdown(query) + "**.")
		}
		lines := []string{"### Engagement search · " + escapeMarkdown(query)}
		for _, event := range response.Engagements {
			lines = append(lines, fmt.Sprintf(
				"- **#%d** [%s](%s) · %s · %s",
				event.ID,
				escapeMarkdown(event.Title),
				event.URL,
				formatDateRange(event.EventStartDate, event.EventEndDate),
				formatStatus(event.LifecycleStatus),
			))
		}
		lines = append(lines, "Use `/moed event show ID` for a card.")
		return ephemeral(strings.Join(lines, "\n"))
	}
	if strings.HasPrefix(input, "event show ") {
		id, err := strconv.Atoi(strings.TrimSpace(strings.TrimPrefix(input, "event show ")))
		if err != nil || id < 1 {
			return ephemeral("Usage: `/moed event show ID`")
		}
		response, apiErr := client.event(ctx, user.Id, user.Username, id)
		if apiErr != nil {
			return commandError(apiErr, config.MoedURL+"/mattermost.php")
		}
		return p.sendCustomEphemeral(user.Id, channelID, customCommandResponse(
			model.CommandResponseTypeEphemeral,
			postTypeEvent,
			"MOED engagement **#"+strconv.Itoa(id)+"**",
			response.Engagement,
		))
	}
	return ephemeral("Usage: `/moed event search TEXT` or `/moed event show ID`")
}

func (p *Plugin) executeLinkEvent(
	ctx context.Context,
	client *moedClient,
	user *model.User,
	config *configuration,
	channelID string,
	input string,
) *model.CommandResponse {
	if !config.EnableChannelLinks {
		return ephemeral("Channel-to-engagement links are disabled by the Mattermost administrator.")
	}
	id, err := strconv.Atoi(strings.TrimSpace(strings.TrimPrefix(input, "link-event")))
	if err != nil || id < 1 {
		return ephemeral("Usage: `/moed link-event ID`")
	}
	response, apiErr := client.event(ctx, user.Id, user.Username, id)
	if apiErr != nil {
		return commandError(apiErr, config.MoedURL+"/mattermost.php")
	}
	if response.User.Role != "editor" && response.User.Role != "admin" {
		return ephemeral("Your MOED role cannot bind a channel to an engagement.")
	}
	if err := p.setChannelBinding(channelID, &channelBinding{
		EngagementID: id,
		LinkedBy:     user.Id,
		LinkedAt:     time.Now().Unix(),
	}); err != nil {
		return ephemeral(":warning: The channel binding could not be saved.")
	}
	postResponse := customCommandResponse(
		model.CommandResponseTypeInChannel,
		postTypeEvent,
		"This channel is now linked to MOED engagement **#"+strconv.Itoa(id)+"**.",
		response.Engagement,
	)
	if _, appErr := p.API.CreatePost(&model.Post{
		UserId:    p.botID,
		ChannelId: channelID,
		Message:   postResponse.Text,
		Type:      postResponse.Type,
		Props:     postResponse.Props,
	}); appErr != nil {
		_ = p.setChannelBinding(channelID, nil)
		return ephemeral(":warning: The channel link could not be announced, so it was not saved.")
	}
	return ephemeral(":white_check_mark: Linked this channel to MOED engagement **#" + strconv.Itoa(id) + "**.")
}

func (p *Plugin) executeUnlinkEvent(
	ctx context.Context,
	client *moedClient,
	user *model.User,
	config *configuration,
	channelID string,
) *model.CommandResponse {
	if !config.EnableChannelLinks {
		return ephemeral("Channel-to-engagement links are disabled by the Mattermost administrator.")
	}
	response, err := client.me(ctx, user.Id, user.Username)
	if err != nil {
		return commandError(err, config.MoedURL+"/mattermost.php")
	}
	if response.User.Role != "editor" && response.User.Role != "admin" {
		return ephemeral("Your MOED role cannot remove a channel binding.")
	}
	binding, bindingErr := p.channelBinding(channelID)
	if bindingErr != nil {
		return ephemeral(":warning: The channel binding could not be read.")
	}
	if binding == nil {
		return ephemeral("This channel is not linked to a MOED engagement.")
	}
	if err := p.setChannelBinding(channelID, nil); err != nil {
		return ephemeral(":warning: The channel binding could not be removed.")
	}
	return ephemeral(":white_check_mark: Removed the link to MOED engagement **#" + strconv.Itoa(binding.EngagementID) + "**.")
}
