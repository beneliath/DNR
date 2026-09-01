package main

import (
	"bytes"
	"encoding/gob"
	"encoding/json"
	"testing"

	"github.com/mattermost/mattermost/server/public/model"
)

func TestTodayCommandUsesNativeWebappPost(t *testing.T) {
	response := &todayResponse{
		BusinessDate: "2026-08-31",
		TaskSummary: apiTaskDashboardSummary{
			Overdue:       2,
			DueToday:      3,
			NextSevenDays: 6,
			Waiting:       1,
		},
	}
	command := todayCommandResponse(response)
	if command.ResponseType != model.CommandResponseTypeEphemeral || command.Type != postTypeToday {
		t.Fatalf("unexpected command response: %#v", command)
	}
	payloadJSON, ok := command.Props["moed"].(string)
	if !ok {
		t.Fatal("the native post payload must cross the plugin RPC boundary as JSON")
	}
	var payload todayResponse
	if err := json.Unmarshal([]byte(payloadJSON), &payload); err != nil || payload.BusinessDate != response.BusinessDate {
		t.Fatalf("the native post payload was not preserved: %v", err)
	}
	if command.Props["type"] != postTypeToday {
		t.Fatal("the ephemeral post type override was not preserved")
	}
	if _, hasLegacyAttachments := command.Props["attachments"]; hasLegacyAttachments {
		t.Fatal("the native dashboard must not render legacy attachments")
	}
}

func TestCustomCommandResponseCrossesMattermostRPCBoundary(t *testing.T) {
	command := customCommandResponse(
		model.CommandResponseTypeEphemeral,
		postTypeEvent,
		"Event",
		apiEngagement{ID: 1, Title: "RPC-safe event"},
	)
	var encoded bytes.Buffer
	if err := gob.NewEncoder(&encoded).Encode(command); err != nil {
		t.Fatalf("custom command response must be gob encodable: %v", err)
	}
}

func TestPrivateEventPayloadPreservesRoutingMarker(t *testing.T) {
	command := customCommandResponse(
		model.CommandResponseTypeEphemeral,
		postTypeEvent,
		"Event",
		apiEngagement{ID: 42, Title: "Private event", EmailRoutingMarker: "[MOED#42]"},
	)
	payloadJSON, ok := command.Props["moed"].(string)
	if !ok {
		t.Fatal("the private event payload must cross the plugin RPC boundary as JSON")
	}
	var event apiEngagement
	if err := json.Unmarshal([]byte(payloadJSON), &event); err != nil {
		t.Fatalf("decode private event payload: %v", err)
	}
	if event.EmailRoutingMarker != "[MOED#42]" {
		t.Fatalf("routing marker was not preserved: %q", event.EmailRoutingMarker)
	}
}

func TestChannelVisibleEventPreservesRoutingMarker(t *testing.T) {
	event := apiEngagement{
		ID:                 42,
		Title:              "Channel event",
		EmailRoutingMarker: "[MOED#42]",
	}
	command := customCommandResponse(
		model.CommandResponseTypeInChannel,
		postTypeEvent,
		"Linked event",
		event,
	)
	payloadJSON, ok := command.Props["moed"].(string)
	if !ok {
		t.Fatal("the channel event payload must cross the plugin RPC boundary as JSON")
	}
	var payload apiEngagement
	if err := json.Unmarshal([]byte(payloadJSON), &payload); err != nil {
		t.Fatalf("decode channel event payload: %v", err)
	}
	if payload.EmailRoutingMarker != "[MOED#42]" {
		t.Fatal("channel-visible event cards must preserve the routing marker")
	}
}

func TestTasksCommandUsesNativeWebappPost(t *testing.T) {
	response := &todayResponse{BusinessDate: "2026-08-31"}
	command := tasksCommandResponse(response)
	if command.ResponseType != model.CommandResponseTypeEphemeral || command.Type != postTypeTasks {
		t.Fatalf("unexpected command response: %#v", command)
	}
}
