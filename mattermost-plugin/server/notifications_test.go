package main

import (
	"strings"
	"testing"

	"github.com/mattermost/mattermost/server/public/model"
	"github.com/mattermost/mattermost/server/public/plugin/plugintest"
	"github.com/stretchr/testify/mock"
)

func TestReplyNotificationIsPrivateAndContainsNoEmailBody(t *testing.T) {
	api := &plugintest.API{}
	api.On("GetUser", "mattermost-user").Return(&model.User{Id: "mattermost-user"}, nil)
	api.On("GetDirectChannel", "bot-user", "mattermost-user").Return(&model.Channel{Id: "direct-channel"}, nil)
	api.On("CreatePost", mock.MatchedBy(func(post *model.Post) bool {
		return post.ChannelId == "direct-channel" &&
			strings.Contains(post.Message, "New email reply in MOED") &&
			strings.Contains(post.Message, "Reply subject") &&
			!strings.Contains(post.Message, "PRIVATE EMAIL BODY")
	})).Return(&model.Post{Id: "notice"}, nil)
	defer api.AssertExpectations(t)

	plugin := &Plugin{botID: "bot-user"}
	plugin.SetAPI(api)
	err := plugin.sendReplyNotification(replyNotification{
		ID:               7,
		MattermostUserID: "mattermost-user",
		EngagementID:     42,
		EngagementTitle:  "Test engagement",
		SenderName:       "Host",
		SenderAddress:    "host@example.test",
		Subject:          "Reply subject",
		AttachmentCount:  1,
		URL:              "https://moed.example.test/view_engagement.php?id=42#chron",
	})
	if err != nil {
		t.Fatalf("send private reply notification: %v", err)
	}
}
