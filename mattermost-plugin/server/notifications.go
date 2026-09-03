package main

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/mattermost/mattermost/server/public/model"
)

const (
	notificationInitialDelay             = 5 * time.Second
	replyNotificationPollInterval        = 60 * time.Second
	postReactionNotificationPollInterval = 15 * time.Second
	chronPostReactionEmoji               = "memo"
	emailPostReactionEmoji               = "email"
)

func (p *Plugin) startNotificationWorkers() {
	p.notificationLifecycleLock.Lock()
	defer p.notificationLifecycleLock.Unlock()
	if p.notificationCancel != nil {
		return
	}
	lifecycleContext, cancel := context.WithCancel(context.Background())
	p.notificationCancel = cancel
	p.notificationWorkers.Add(2)
	go func() {
		defer p.notificationWorkers.Done()
		runNotificationWorker(
			lifecycleContext,
			notificationInitialDelay,
			replyNotificationPollInterval,
			p.pollReplyNotifications,
		)
	}()
	go func() {
		defer p.notificationWorkers.Done()
		runNotificationWorker(
			lifecycleContext,
			notificationInitialDelay,
			postReactionNotificationPollInterval,
			p.pollPostReactionNotifications,
		)
	}()
}

func runNotificationWorker(
	ctx context.Context,
	initialDelay time.Duration,
	interval time.Duration,
	poll func(context.Context),
) {
	timer := time.NewTimer(initialDelay)
	defer timer.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
			poll(ctx)
			timer.Reset(interval)
		}
	}
}

func (p *Plugin) pollPostReactionNotifications(parentContext context.Context) {
	config := p.getConfiguration()
	if config == nil || p.botID == "" {
		return
	}
	ctx, cancel := context.WithTimeout(parentContext, config.timeout())
	client := newMoedClient(config)
	response, err := client.postReactionNotifications(ctx)
	cancel()
	if err != nil {
		if parentContext.Err() == nil {
			p.API.LogError("Unable to retrieve MOED post reaction notifications", "error", err.Error())
		}
		return
	}
	for _, notification := range response.Notifications {
		if parentContext.Err() != nil {
			return
		}
		if notification.ID < 1 || strings.TrimSpace(notification.MattermostPostID) == "" {
			continue
		}
		reactionErr := p.addPostReaction(notification.MattermostPostID, notification.ReactionName)
		if reactionErr != nil {
			followupContext, followupCancel := context.WithTimeout(parentContext, config.timeout())
			reportErr := client.failPostReactionNotification(followupContext, notification.ID, reactionErr.Error())
			followupCancel()
			if reportErr != nil && parentContext.Err() == nil {
				p.API.LogError(
					"Unable to report a failed MOED post reaction notification",
					"notification_id", notification.ID,
					"reaction_error", reactionErr.Error(),
					"report_error", reportErr.Error(),
				)
				return
			}
			continue
		}
		followupContext, followupCancel := context.WithTimeout(parentContext, config.timeout())
		err := client.acknowledgePostReactionNotification(followupContext, notification.ID)
		followupCancel()
		if err != nil && parentContext.Err() == nil {
			p.API.LogError(
				"Unable to acknowledge a MOED post reaction notification",
				"notification_id", notification.ID,
				"error", err.Error(),
			)
			return
		}
	}
}

func (p *Plugin) addPostReaction(postID, reactionName string) error {
	postID = strings.TrimSpace(postID)
	if p.botID == "" || postID == "" {
		return fmt.Errorf("reaction identity is incomplete")
	}
	if reactionName != chronPostReactionEmoji && reactionName != emailPostReactionEmoji {
		return fmt.Errorf("unsupported Mattermost reaction %q", reactionName)
	}
	_, appErr := p.API.AddReaction(&model.Reaction{
		UserId:    p.botID,
		PostId:    postID,
		EmojiName: reactionName,
	})
	if appErr != nil {
		return fmt.Errorf("add Mattermost reaction: %s", appErr.Error())
	}
	return nil
}

func (p *Plugin) pollReplyNotifications(parentContext context.Context) {
	config := p.getConfiguration()
	if config == nil || p.botID == "" {
		return
	}
	ctx, cancel := context.WithTimeout(parentContext, config.timeout())
	client := newMoedClient(config)
	response, err := client.replyNotifications(ctx)
	cancel()
	if err != nil {
		if parentContext.Err() == nil {
			p.API.LogError("Unable to retrieve MOED reply notifications", "error", err.Error())
		}
		return
	}
	for _, notification := range response.Notifications {
		if parentContext.Err() != nil {
			return
		}
		if notification.ID < 1 || notification.MattermostUserID == "" {
			continue
		}
		if err := p.sendReplyNotification(notification); err != nil {
			p.API.LogError(
				"Unable to deliver a MOED reply notification",
				"notification_id", notification.ID,
				"error", err.Error(),
			)
			continue
		}
		followupContext, followupCancel := context.WithTimeout(parentContext, config.timeout())
		err := client.acknowledgeReplyNotification(followupContext, notification.ID)
		followupCancel()
		if err != nil && parentContext.Err() == nil {
			p.API.LogError(
				"Unable to acknowledge a MOED reply notification",
				"notification_id", notification.ID,
				"error", err.Error(),
			)
			return
		}
	}
}

func (p *Plugin) sendReplyNotification(notification replyNotification) error {
	user, appErr := p.API.GetUser(notification.MattermostUserID)
	if appErr != nil || user == nil || user.DeleteAt != 0 {
		return fmt.Errorf("target Mattermost user is unavailable")
	}
	channel, channelErr := p.API.GetDirectChannel(p.botID, notification.MattermostUserID)
	if channelErr != nil || channel == nil {
		return fmt.Errorf("open private Mattermost channel: %v", channelErr)
	}
	sender := strings.TrimSpace(notification.SenderName)
	if sender == "" {
		sender = notification.SenderAddress
	} else {
		sender += " <" + notification.SenderAddress + ">"
	}
	attachments := ""
	if notification.AttachmentCount > 0 {
		attachments = fmt.Sprintf(
			"\nAttachments: %d",
			notification.AttachmentCount,
		)
	}
	message := fmt.Sprintf(
		"### New email reply in MOED\n**%s** replied about [%s](%s).\nSubject: **%s**%s\n\nThe message was routed privately to the engagement Chron.",
		escapeMarkdown(sender),
		escapeMarkdown(notification.EngagementTitle),
		notification.URL,
		escapeMarkdown(notification.Subject),
		attachments,
	)
	_, createErr := p.API.CreatePost(&model.Post{
		UserId:    p.botID,
		ChannelId: channel.Id,
		Message:   message,
		Props: model.StringInterface{
			"moed_reply_notification_id": notification.ID,
			"moed_engagement_id":         notification.EngagementID,
		},
	})
	if createErr != nil {
		return fmt.Errorf("create private Mattermost reply notice: %s", createErr.Error())
	}
	return nil
}
