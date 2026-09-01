package main

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/mattermost/mattermost/server/public/model"
)

const replyNotificationPollInterval = time.Minute

func (p *Plugin) runReplyNotificationPoller(stop <-chan struct{}, done chan<- struct{}) {
	defer close(done)
	timer := time.NewTimer(5 * time.Second)
	defer timer.Stop()
	for {
		select {
		case <-stop:
			return
		case <-timer.C:
			p.pollReplyNotifications()
			timer.Reset(replyNotificationPollInterval)
		}
	}
}

func (p *Plugin) pollReplyNotifications() {
	config := p.getConfiguration()
	if config == nil || p.botID == "" {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), config.timeout())
	response, err := newMoedClient(config).replyNotifications(ctx)
	cancel()
	if err != nil {
		p.API.LogError("Unable to retrieve MOED reply notifications", "error", err.Error())
		return
	}
	for _, notification := range response.Notifications {
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
		ackContext, ackCancel := context.WithTimeout(context.Background(), config.timeout())
		err := newMoedClient(config).acknowledgeReplyNotification(ackContext, notification.ID)
		ackCancel()
		if err != nil {
			p.API.LogError(
				"Unable to acknowledge a MOED reply notification",
				"notification_id", notification.ID,
				"error", err.Error(),
			)
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
