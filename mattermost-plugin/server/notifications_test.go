package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/mattermost/mattermost/server/public/model"
	"github.com/mattermost/mattermost/server/public/plugin/plugintest"
	"github.com/stretchr/testify/mock"
)

func TestAddPostReactionUsesMOEDBot(t *testing.T) {
	for _, emojiName := range []string{chronPostReactionEmoji, emailPostReactionEmoji} {
		if _, ok := model.GetSystemEmojiId(emojiName); !ok {
			t.Fatalf("%q must be a built-in Mattermost emoji", emojiName)
		}
	}
	api := &plugintest.API{}
	api.On("AddReaction", mock.MatchedBy(func(reaction *model.Reaction) bool {
		return reaction.UserId == "bot-user" && reaction.PostId == "post-id" && reaction.EmojiName == chronPostReactionEmoji
	})).Return(&model.Reaction{UserId: "bot-user", PostId: "post-id", EmojiName: chronPostReactionEmoji}, nil)
	defer api.AssertExpectations(t)

	plugin := &Plugin{botID: "bot-user"}
	plugin.SetAPI(api)
	if err := plugin.addPostReaction("post-id", chronPostReactionEmoji); err != nil {
		t.Fatalf("add post reaction: %v", err)
	}
}

func TestAddPostReactionRejectsNamesOutsideReceiptAllowlist(t *testing.T) {
	plugin := &Plugin{botID: "bot-user"}
	if err := plugin.addPostReaction("post-id", "thumbsup"); err == nil || !strings.Contains(err.Error(), "unsupported") {
		t.Fatalf("expected unsupported reaction to be rejected, got %v", err)
	}
}

func TestPollPostReactionNotificationsMarksAndAcknowledgesDeliveredEmail(t *testing.T) {
	acknowledged := false
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.Header.Get("Authorization") == "" || request.Header.Get("X-Mattermost-Instance-ID") != "primary" {
			t.Fatalf("missing service authentication")
		}
		switch request.URL.Query().Get("action") {
		case "post_reaction_notifications":
			writeJSON(writer, http.StatusOK, postReactionNotificationsResponse{
				OK: true,
				Notifications: []postReactionNotification{{
					ID:                     8,
					MattermostPostID:       "post-id",
					OutboundEmailMessageID: 77,
					ReactionName:           emailPostReactionEmoji,
				}},
			})
		case "post_reaction_notification_ack":
			var body map[string]int
			if err := json.NewDecoder(request.Body).Decode(&body); err != nil || body["notification_id"] != 8 {
				t.Fatalf("invalid acknowledgement: %#v, %v", body, err)
			}
			acknowledged = true
			writeJSON(writer, http.StatusOK, replyNotificationAckResponse{OK: true})
		default:
			t.Fatalf("unexpected action %q", request.URL.Query().Get("action"))
		}
	}))
	defer server.Close()

	api := &plugintest.API{}
	api.On("AddReaction", mock.MatchedBy(func(reaction *model.Reaction) bool {
		return reaction.UserId == "bot-user" && reaction.PostId == "post-id" && reaction.EmojiName == emailPostReactionEmoji
	})).Return(&model.Reaction{UserId: "bot-user", PostId: "post-id", EmojiName: emailPostReactionEmoji}, nil)
	defer api.AssertExpectations(t)

	config := validConfiguration()
	config.MoedURL = server.URL
	plugin := &Plugin{botID: "bot-user", configuration: &config}
	plugin.SetAPI(api)
	plugin.pollPostReactionNotifications(context.Background())
	if !acknowledged {
		t.Fatal("expected delivered email reaction to be acknowledged")
	}
}

func TestPollPostReactionNotificationsReportsFailureAndContinues(t *testing.T) {
	failureReported := false
	successAcknowledged := false
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		switch request.URL.Query().Get("action") {
		case "post_reaction_notifications":
			writeJSON(writer, http.StatusOK, postReactionNotificationsResponse{
				OK: true,
				Notifications: []postReactionNotification{
					{ID: 8, MattermostPostID: "failed-post", ReactionName: chronPostReactionEmoji},
					{ID: 9, MattermostPostID: "success-post", OutboundEmailMessageID: 77, ReactionName: emailPostReactionEmoji},
				},
			})
		case "post_reaction_notification_fail":
			var body struct {
				NotificationID int    `json:"notification_id"`
				Error          string `json:"error"`
			}
			if err := json.NewDecoder(request.Body).Decode(&body); err != nil || body.NotificationID != 8 || !strings.Contains(body.Error, "add Mattermost reaction") {
				t.Fatalf("invalid failure report: %#v, %v", body, err)
			}
			failureReported = true
			writeJSON(writer, http.StatusOK, replyNotificationAckResponse{OK: true})
		case "post_reaction_notification_ack":
			var body map[string]int
			if err := json.NewDecoder(request.Body).Decode(&body); err != nil || body["notification_id"] != 9 {
				t.Fatalf("invalid acknowledgement: %#v, %v", body, err)
			}
			successAcknowledged = true
			writeJSON(writer, http.StatusOK, replyNotificationAckResponse{OK: true})
		default:
			t.Fatalf("unexpected action %q", request.URL.Query().Get("action"))
		}
	}))
	defer server.Close()

	api := &plugintest.API{}
	api.On("AddReaction", mock.MatchedBy(func(reaction *model.Reaction) bool {
		return reaction.PostId == "failed-post" && reaction.EmojiName == chronPostReactionEmoji
	})).Return(nil, model.NewAppError("test", "reaction.failed", nil, "failed", http.StatusInternalServerError)).Once()
	api.On("AddReaction", mock.MatchedBy(func(reaction *model.Reaction) bool {
		return reaction.PostId == "success-post" && reaction.EmojiName == emailPostReactionEmoji
	})).Return(&model.Reaction{UserId: "bot-user", PostId: "success-post", EmojiName: emailPostReactionEmoji}, nil).Once()
	defer api.AssertExpectations(t)

	config := validConfiguration()
	config.MoedURL = server.URL
	plugin := &Plugin{botID: "bot-user", configuration: &config}
	plugin.SetAPI(api)
	plugin.pollPostReactionNotifications(context.Background())
	if !failureReported || !successAcknowledged {
		t.Fatalf("expected failure report and later acknowledgement, got failure=%t ack=%t", failureReported, successAcknowledged)
	}
}

func TestPollPostReactionNotificationsLogsFailureReportingError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		switch request.URL.Query().Get("action") {
		case "post_reaction_notifications":
			writeJSON(writer, http.StatusOK, postReactionNotificationsResponse{
				OK: true,
				Notifications: []postReactionNotification{{
					ID: 8, MattermostPostID: "failed-post", ReactionName: chronPostReactionEmoji,
				}},
			})
		case "post_reaction_notification_fail":
			writeJSON(writer, http.StatusInternalServerError, apiError{Error: "retry state unavailable"})
		default:
			t.Fatalf("unexpected action %q", request.URL.Query().Get("action"))
		}
	}))
	defer server.Close()

	api := &plugintest.API{}
	api.On("AddReaction", mock.Anything).Return(
		nil,
		model.NewAppError("test", "reaction.failed", nil, "failed", http.StatusInternalServerError),
	).Once()
	api.On(
		"LogError",
		"Unable to report a failed MOED post reaction notification",
		"notification_id", 8,
		"reaction_error", mock.MatchedBy(func(value string) bool { return strings.Contains(value, "add Mattermost reaction") }),
		"report_error", mock.MatchedBy(func(value string) bool { return strings.Contains(value, "retry state unavailable") }),
	).Return().Once()
	defer api.AssertExpectations(t)

	config := validConfiguration()
	config.MoedURL = server.URL
	plugin := &Plugin{botID: "bot-user", configuration: &config}
	plugin.SetAPI(api)
	plugin.pollPostReactionNotifications(context.Background())
}

func TestPollPostReactionNotificationsStopsAfterCancellation(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Query().Get("action") != "post_reaction_notifications" {
			t.Fatalf("unexpected action %q", request.URL.Query().Get("action"))
		}
		writeJSON(writer, http.StatusOK, postReactionNotificationsResponse{
			OK: true,
			Notifications: []postReactionNotification{
				{ID: 8, MattermostPostID: "first-post", ReactionName: chronPostReactionEmoji},
				{ID: 9, MattermostPostID: "second-post", ReactionName: emailPostReactionEmoji},
			},
		})
	}))
	defer server.Close()

	ctx, cancel := context.WithCancel(context.Background())
	api := &plugintest.API{}
	api.On("AddReaction", mock.MatchedBy(func(reaction *model.Reaction) bool {
		return reaction.PostId == "first-post"
	})).Run(func(mock.Arguments) {
		cancel()
	}).Return(&model.Reaction{UserId: "bot-user", PostId: "first-post", EmojiName: chronPostReactionEmoji}, nil).Once()
	defer api.AssertExpectations(t)

	config := validConfiguration()
	config.MoedURL = server.URL
	plugin := &Plugin{botID: "bot-user", configuration: &config}
	plugin.SetAPI(api)
	plugin.pollPostReactionNotifications(ctx)
}

func TestPollReplyNotificationsStopsAfterCancellation(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Query().Get("action") != "reply_notifications" {
			t.Fatalf("unexpected action %q", request.URL.Query().Get("action"))
		}
		writeJSON(writer, http.StatusOK, replyNotificationsResponse{
			OK: true,
			Notifications: []replyNotification{
				{ID: 8, MattermostUserID: "first-user", EngagementID: 42, EngagementTitle: "First", SenderAddress: "first@example.test", Subject: "First reply", URL: "https://moed.example.test/first"},
				{ID: 9, MattermostUserID: "second-user", EngagementID: 43, EngagementTitle: "Second", SenderAddress: "second@example.test", Subject: "Second reply", URL: "https://moed.example.test/second"},
			},
		})
	}))
	defer server.Close()

	ctx, cancel := context.WithCancel(context.Background())
	api := &plugintest.API{}
	api.On("GetUser", "first-user").Return(&model.User{Id: "first-user"}, nil).Once()
	api.On("GetDirectChannel", "bot-user", "first-user").Return(&model.Channel{Id: "direct-channel"}, nil).Once()
	api.On("CreatePost", mock.MatchedBy(func(post *model.Post) bool {
		return post.ChannelId == "direct-channel"
	})).Run(func(mock.Arguments) {
		cancel()
	}).Return(&model.Post{Id: "notice"}, nil).Once()
	defer api.AssertExpectations(t)

	config := validConfiguration()
	config.MoedURL = server.URL
	plugin := &Plugin{botID: "bot-user", configuration: &config}
	plugin.SetAPI(api)
	plugin.pollReplyNotifications(ctx)
}

func TestNotificationWorkersRunIndependently(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	blockedStarted := make(chan struct{})
	releaseBlocked := make(chan struct{})
	fastPolled := make(chan struct{}, 1)
	done := make(chan struct{}, 2)

	go func() {
		runNotificationWorker(ctx, 0, time.Hour, func(context.Context) {
			close(blockedStarted)
			<-releaseBlocked
		})
		done <- struct{}{}
	}()
	go func() {
		runNotificationWorker(ctx, 0, time.Hour, func(context.Context) {
			fastPolled <- struct{}{}
		})
		done <- struct{}{}
	}()

	select {
	case <-blockedStarted:
	case <-time.After(time.Second):
		t.Fatal("blocking worker did not start")
	}
	select {
	case <-fastPolled:
	case <-time.After(time.Second):
		t.Fatal("second worker was blocked by the first")
	}
	close(releaseBlocked)
	cancel()
	for range 2 {
		select {
		case <-done:
		case <-time.After(time.Second):
			t.Fatal("notification worker did not stop after cancellation")
		}
	}
}

func TestPluginDeactivationCancelsNotificationWorkers(t *testing.T) {
	plugin := &Plugin{}
	plugin.startNotificationWorkers()
	if err := plugin.OnDeactivate(); err != nil {
		t.Fatalf("deactivate plugin: %v", err)
	}
	if err := plugin.OnDeactivate(); err != nil {
		t.Fatalf("deactivate plugin twice: %v", err)
	}
}

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
