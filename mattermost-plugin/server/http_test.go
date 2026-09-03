package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/mattermost/mattermost/server/public/model"
	"github.com/mattermost/mattermost/server/public/plugin/plugintest"
)

func TestPostActionRequiresAuthenticatedMattermostRequestForBothActions(t *testing.T) {
	testCases := []struct {
		name string
		body string
	}{
		{
			name: "add MOED task",
			body: `{
				"post_id":"post-id",
				"action":"create_task",
				"title":"Follow up",
				"idempotency_key":"create:12345678"
			}`,
		},
		{
			name: "add to MOED Chron",
			body: `{
				"post_id":"post-id",
				"action":"save_chron",
				"entry_text":"Important decision",
				"idempotency_key":"chron:12345678"
			}`,
		},
	}

	for _, testCase := range testCases {
		t.Run(testCase.name, func(t *testing.T) {
			plugin := &Plugin{}
			recorder := httptest.NewRecorder()
			request := httptest.NewRequest(http.MethodPost, "/api/v1/post-action", strings.NewReader(testCase.body))

			plugin.handlePostAction(recorder, request)

			if recorder.Code != http.StatusUnauthorized {
				t.Fatalf("expected unauthorized, got %d: %s", recorder.Code, recorder.Body.String())
			}
			if !strings.Contains(recorder.Body.String(), "could not authenticate") {
				t.Fatalf("expected a specific authentication error, got %s", recorder.Body.String())
			}
		})
	}
}

func TestPostActionRejectsPostsOutsideUserChannelAccess(t *testing.T) {
	api := &plugintest.API{}
	post := &model.Post{Id: "post-id", ChannelId: "channel-id", UserId: "author-id", Message: "Follow up"}
	api.On("GetPost", post.Id).Return(post, nil)
	api.On("HasPermissionToChannel", "requesting-user", post.ChannelId, model.PermissionReadChannel).Return(false)
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	recorder := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodPost, "/api/v1/post-action", strings.NewReader(`{
		"post_id":"post-id",
		"action":"create_task",
		"title":"Follow up",
		"idempotency_key":"create:12345678"
	}`))
	request.Header.Set("Mattermost-User-Id", "requesting-user")
	plugin.handlePostAction(recorder, request)
	if recorder.Code != http.StatusForbidden {
		t.Fatalf("expected forbidden, got %d: %s", recorder.Code, recorder.Body.String())
	}
}

func TestPostActionRequiresServerSideChannelBinding(t *testing.T) {
	api := &plugintest.API{}
	post := &model.Post{Id: "post-id", ChannelId: "channel-id", UserId: "author-id", Message: "Follow up"}
	api.On("GetPost", post.Id).Return(post, nil)
	api.On("HasPermissionToChannel", "requesting-user", post.ChannelId, model.PermissionReadChannel).Return(true)
	api.On("KVGet", "channel_binding:"+post.ChannelId).Return([]byte(nil), nil)
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	recorder := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodPost, "/api/v1/post-action", strings.NewReader(`{
		"post_id":"post-id",
		"action":"save_chron",
		"entry_text":"Important decision",
		"idempotency_key":"chron:12345678"
	}`))
	request.Header.Set("Mattermost-User-Id", "requesting-user")
	plugin.handlePostAction(recorder, request)
	if recorder.Code != http.StatusConflict {
		t.Fatalf("expected channel-binding conflict, got %d: %s", recorder.Code, recorder.Body.String())
	}
}

func TestPostActionRejectsBrowserSuppliedEngagementID(t *testing.T) {
	plugin := &Plugin{}
	recorder := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodPost, "/api/v1/post-action", strings.NewReader(`{
		"post_id":"post-id",
		"action":"create_task",
		"title":"Follow up",
		"engagement_id":999,
		"idempotency_key":"create:12345678"
	}`))
	request.Header.Set("Mattermost-User-Id", "requesting-user")
	plugin.handlePostAction(recorder, request)
	if recorder.Code != http.StatusBadRequest {
		t.Fatalf("expected browser engagement ID to be rejected, got %d", recorder.Code)
	}
}

func TestSaveChronSendsSourcePostIDForDurableReaction(t *testing.T) {
	postID := "aaaaaaaaaaaaaaaaaaaaaaaaaa"
	moed := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Query().Get("action") != "save_chron" {
			t.Fatalf("unexpected MOED action: %s", request.URL.String())
		}
		var body saveChronRequest
		if err := json.NewDecoder(request.Body).Decode(&body); err != nil {
			t.Fatalf("decode Chron request: %v", err)
		}
		if body.EngagementID != 42 || body.MattermostPostID != postID {
			t.Fatalf("unexpected Chron request: %#v", body)
		}
		writeJSON(writer, http.StatusOK, map[string]any{
			"ok":      true,
			"message": "Post saved to the MOED Chron.",
			"url":     "https://moed.example.test/view_engagement.php?id=42",
		})
	}))
	defer moed.Close()

	post := &model.Post{Id: postID, ChannelId: "channel-id", UserId: "author-id", Message: "Important decision"}
	api := &plugintest.API{}
	api.On("GetPost", post.Id).Return(post, nil)
	api.On("HasPermissionToChannel", "requesting-user", post.ChannelId, model.PermissionReadChannel).Return(true)
	api.On("KVGet", "channel_binding:"+post.ChannelId).Return([]byte(`{"engagement_id":42}`), nil)
	api.On("GetUser", "requesting-user").Return(&model.User{Id: "requesting-user", Username: "requester"}, nil)
	api.On("GetUser", "author-id").Return(&model.User{Id: "author-id", Username: "author"}, nil)
	api.On("GetChannel", post.ChannelId).Return(&model.Channel{Id: post.ChannelId, DisplayName: "Planning"}, nil)
	defer api.AssertExpectations(t)

	config := validConfiguration()
	config.MoedURL = moed.URL
	plugin := &Plugin{botID: "bot-user", configuration: &config}
	plugin.SetAPI(api)
	recorder := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodPost, "/api/v1/post-action", strings.NewReader(`{
		"post_id":"aaaaaaaaaaaaaaaaaaaaaaaaaa",
		"action":"save_chron",
		"entry_text":"Important decision",
		"idempotency_key":"chron:12345678"
	}`))
	request.Header.Set("Mattermost-User-Id", "requesting-user")
	plugin.handlePostAction(recorder, request)
	if recorder.Code != http.StatusOK {
		t.Fatalf("expected successful Chron write, got %d: %s", recorder.Code, recorder.Body.String())
	}
}

func TestEmailSendRejectsBrowserSuppliedEngagementID(t *testing.T) {
	plugin := &Plugin{}
	recorder := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodPost, "/api/v1/email-send", strings.NewReader(`{
		"channel_id":"channel-id",
		"engagement_id":999,
		"contact_ids":[1],
		"subject":"Hello",
		"body":"Message",
		"idempotency_key":"send-email:12345678"
	}`))
	request.Header.Set("Mattermost-User-Id", "requesting-user")
	plugin.handleEmailSend(recorder, request)
	if recorder.Code != http.StatusBadRequest {
		t.Fatalf("expected browser engagement ID to be rejected, got %d", recorder.Code)
	}
}

func TestEmailSendRequiresServerSideChannelBinding(t *testing.T) {
	api := &plugintest.API{}
	api.On("HasPermissionToChannel", "requesting-user", "channel-id", model.PermissionReadChannel).Return(true)
	api.On("KVGet", "channel_binding:channel-id").Return([]byte(nil), nil)
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	recorder := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodPost, "/api/v1/email-send", strings.NewReader(`{
		"channel_id":"channel-id",
		"contact_ids":[1],
		"subject":"Hello",
		"body":"Message",
		"idempotency_key":"send-email:12345678"
	}`))
	request.Header.Set("Mattermost-User-Id", "requesting-user")
	plugin.handleEmailSend(recorder, request)
	if recorder.Code != http.StatusConflict {
		t.Fatalf("expected channel-binding conflict, got %d: %s", recorder.Code, recorder.Body.String())
	}
}

func TestEmailContextRejectsPostFromAnotherChannel(t *testing.T) {
	api := &plugintest.API{}
	api.On("GetPost", "post-id").Return(&model.Post{Id: "post-id", ChannelId: "other-channel"}, nil)
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	_, _, err := plugin.mattermostEmailContexts("requesting-user", "channel-id", "post-id")
	if err == nil {
		t.Fatal("expected post context from another channel to be rejected")
	}
}

func TestMattermostEmailReactionPostIDRequiresIncludedContext(t *testing.T) {
	testCases := []struct {
		name     string
		request  emailSendWebRequest
		expected string
	}{
		{name: "selected post", request: emailSendWebRequest{PostID: "post-id", IncludePost: true}, expected: "post-id"},
		{name: "selected thread", request: emailSendWebRequest{PostID: "post-id", IncludeThread: true}, expected: "post-id"},
		{name: "context excluded", request: emailSendWebRequest{PostID: "post-id"}, expected: ""},
		{name: "channel composer", request: emailSendWebRequest{IncludePost: true}, expected: ""},
	}
	for _, testCase := range testCases {
		t.Run(testCase.name, func(t *testing.T) {
			if actual := mattermostEmailReactionPostID(testCase.request); actual != testCase.expected {
				t.Fatalf("expected %q, got %q", testCase.expected, actual)
			}
		})
	}
}
