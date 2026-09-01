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
