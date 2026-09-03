package main

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"strings"
	"testing"
)

type roundTripFunc func(*http.Request) (*http.Response, error)

func (function roundTripFunc) RoundTrip(request *http.Request) (*http.Response, error) {
	return function(request)
}

func TestMoedClientSendsAuthenticatedIdentity(t *testing.T) {
	config := validConfiguration()
	client := newMoedClient(&config)
	client.httpClient.Transport = roundTripFunc(func(request *http.Request) (*http.Response, error) {
		if request.URL.Path != "/api/v1/mattermost.php" || request.URL.Query().Get("action") != "connect" {
			t.Fatalf("unexpected endpoint: %s", request.URL.String())
		}
		if request.Header.Get("Authorization") != "Bearer 0123456789abcdef0123456789abcdef" {
			t.Fatal("missing bearer token")
		}
		if request.Header.Get("X-Mattermost-Instance-ID") != "primary" {
			t.Fatal("missing instance ID")
		}
		if request.Header.Get("X-Mattermost-User-ID") != "mattermost-user" || request.Header.Get("X-Mattermost-Username") != "alex" {
			t.Fatal("missing Mattermost identity")
		}
		var body map[string]string
		if err := json.NewDecoder(request.Body).Decode(&body); err != nil || body["code"] != "ABCD-2345" {
			t.Fatal("invalid request body")
		}
		return &http.Response{
			StatusCode: http.StatusOK,
			Header:     http.Header{"Content-Type": []string{"application/json"}},
			Body:       io.NopCloser(strings.NewReader(`{"ok":true,"message":"linked","user":{"id":1,"username":"alex","role":"editor"}}`)),
		}, nil
	})
	response, err := client.connect(context.Background(), "mattermost-user", "alex", "ABCD-2345")
	if err != nil {
		t.Fatalf("connect failed: %v", err)
	}
	if response.User.Role != "editor" {
		t.Fatalf("unexpected role: %s", response.User.Role)
	}
}

func TestMoedClientPreservesStructuredErrors(t *testing.T) {
	config := validConfiguration()
	client := newMoedClient(&config)
	client.httpClient.Transport = roundTripFunc(func(_ *http.Request) (*http.Response, error) {
		return &http.Response{
			StatusCode: http.StatusForbidden,
			Header:     http.Header{"Content-Type": []string{"application/json"}},
			Body:       io.NopCloser(strings.NewReader(`{"error":"Link first.","code":"account_not_linked","request_id":"request-1"}`)),
		}, nil
	})
	_, err := client.me(context.Background(), "mattermost-user", "alex")
	typed, ok := err.(*moedAPIError)
	if !ok || typed.StatusCode != http.StatusForbidden || typed.Payload.Code != "account_not_linked" {
		t.Fatalf("unexpected error: %#v", err)
	}
}

func TestMoedClientSendsEngagementEmailWithIdempotency(t *testing.T) {
	config := validConfiguration()
	client := newMoedClient(&config)
	client.httpClient.Transport = roundTripFunc(func(request *http.Request) (*http.Response, error) {
		if request.Method != http.MethodPost || request.URL.Query().Get("action") != "email_send" {
			t.Fatalf("unexpected endpoint: %s %s", request.Method, request.URL.String())
		}
		if request.Header.Get("Idempotency-Key") != "send-email:12345678" {
			t.Fatal("missing email idempotency key")
		}
		if request.Header.Get("X-Mattermost-User-ID") != "mattermost-user" {
			t.Fatal("missing sender identity")
		}
		var body sendEmailRequest
		if err := json.NewDecoder(request.Body).Decode(&body); err != nil {
			t.Fatalf("decode email body: %v", err)
		}
		if body.EngagementID != 42 || len(body.ContactIDs) != 1 || body.ContactIDs[0] != 9 || body.MattermostContext != "MATTERMOST POST" || body.MattermostPostID != "mattermost-post-id" {
			t.Fatalf("unexpected email request: %#v", body)
		}
		return &http.Response{
			StatusCode: http.StatusOK,
			Header:     http.Header{"Content-Type": []string{"application/json"}},
			Body:       io.NopCloser(strings.NewReader(`{"ok":true,"message_id":77,"engagement_id":42,"pending_count":1}`)),
		}, nil
	})
	response, err := client.sendEmail(context.Background(), "mattermost-user", "alex", sendEmailRequest{
		EngagementID:      42,
		ContactIDs:        []int{9},
		TemplateKey:       "custom",
		Subject:           "Subject [MOED#42]",
		Body:              "Message",
		MattermostContext: "MATTERMOST POST",
		MattermostPostID:  "mattermost-post-id",
	}, "send-email:12345678")
	if err != nil || response.MessageID != 77 || response.EngagementID != 42 {
		t.Fatalf("email send failed: %#v, %v", response, err)
	}
}

func TestMoedClientPollsAndAcknowledgesPostReactionNotificationsAsService(t *testing.T) {
	config := validConfiguration()
	client := newMoedClient(&config)
	acknowledged := false
	client.httpClient.Transport = roundTripFunc(func(request *http.Request) (*http.Response, error) {
		if request.Header.Get("X-Mattermost-User-ID") != "" || request.Header.Get("Authorization") == "" {
			t.Fatal("post reaction notifications must use service authentication without impersonating a user")
		}
		switch request.URL.Query().Get("action") {
		case "post_reaction_notifications":
			return &http.Response{
				StatusCode: http.StatusOK,
				Header:     http.Header{"Content-Type": []string{"application/json"}},
				Body:       io.NopCloser(strings.NewReader(`{"ok":true,"notifications":[{"id":8,"mattermost_post_id":"post-id","outbound_email_message_id":77,"reaction_name":"email"}]}`)),
			}, nil
		case "post_reaction_notification_ack":
			var body map[string]int
			if err := json.NewDecoder(request.Body).Decode(&body); err != nil || body["notification_id"] != 8 {
				t.Fatalf("invalid acknowledgement body: %#v, %v", body, err)
			}
			acknowledged = true
			return &http.Response{
				StatusCode: http.StatusOK,
				Header:     http.Header{"Content-Type": []string{"application/json"}},
				Body:       io.NopCloser(strings.NewReader(`{"ok":true}`)),
			}, nil
		default:
			t.Fatalf("unexpected endpoint: %s", request.URL.String())
			return nil, nil
		}
	})
	response, err := client.postReactionNotifications(context.Background())
	if err != nil || len(response.Notifications) != 1 || response.Notifications[0].MattermostPostID != "post-id" || response.Notifications[0].ReactionName != emailPostReactionEmoji {
		t.Fatalf("post reaction notification poll failed: %#v, %v", response, err)
	}
	if err := client.acknowledgePostReactionNotification(context.Background(), response.Notifications[0].ID); err != nil {
		t.Fatalf("post reaction notification acknowledgement failed: %v", err)
	}
	if !acknowledged {
		t.Fatal("expected acknowledgement request")
	}
}

func TestMoedClientReportsBoundedPostReactionFailureAsService(t *testing.T) {
	config := validConfiguration()
	client := newMoedClient(&config)
	client.httpClient.Transport = roundTripFunc(func(request *http.Request) (*http.Response, error) {
		if request.URL.Query().Get("action") != "post_reaction_notification_fail" {
			t.Fatalf("unexpected endpoint: %s", request.URL.String())
		}
		if request.Header.Get("X-Mattermost-User-ID") != "" || request.Header.Get("Authorization") == "" {
			t.Fatal("post reaction failure reporting must use service authentication without impersonating a user")
		}
		var body struct {
			NotificationID int    `json:"notification_id"`
			Error          string `json:"error"`
		}
		if err := json.NewDecoder(request.Body).Decode(&body); err != nil {
			t.Fatalf("decode failure body: %v", err)
		}
		if body.NotificationID != 9 || len([]rune(body.Error)) != 500 {
			t.Fatalf("failure body was not bounded correctly: id=%d length=%d", body.NotificationID, len([]rune(body.Error)))
		}
		return &http.Response{
			StatusCode: http.StatusOK,
			Header:     http.Header{"Content-Type": []string{"application/json"}},
			Body:       io.NopCloser(strings.NewReader(`{"ok":true}`)),
		}, nil
	})
	if err := client.failPostReactionNotification(context.Background(), 9, strings.Repeat("failure", 100)); err != nil {
		t.Fatalf("report post reaction failure: %v", err)
	}
}

func TestMoedClientPollsReplyNotificationsAsService(t *testing.T) {
	config := validConfiguration()
	client := newMoedClient(&config)
	client.httpClient.Transport = roundTripFunc(func(request *http.Request) (*http.Response, error) {
		if request.URL.Query().Get("action") != "reply_notifications" {
			t.Fatalf("unexpected endpoint: %s", request.URL.String())
		}
		if request.Header.Get("X-Mattermost-User-ID") != "" || request.Header.Get("Authorization") == "" {
			t.Fatal("reply polling must use service authentication without impersonating a user")
		}
		return &http.Response{
			StatusCode: http.StatusOK,
			Header:     http.Header{"Content-Type": []string{"application/json"}},
			Body:       io.NopCloser(strings.NewReader(`{"ok":true,"notifications":[]}`)),
		}, nil
	})
	response, err := client.replyNotifications(context.Background())
	if err != nil || len(response.Notifications) != 0 {
		t.Fatalf("reply notification poll failed: %#v, %v", response, err)
	}
}
