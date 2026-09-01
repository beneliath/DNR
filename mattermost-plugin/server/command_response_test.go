package main

import (
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
	if command.Props["moed"] != response {
		t.Fatal("the native post payload was not preserved")
	}
	if command.Props["type"] != postTypeToday {
		t.Fatal("the ephemeral post type override was not preserved")
	}
	if _, hasLegacyAttachments := command.Props["attachments"]; hasLegacyAttachments {
		t.Fatal("the native dashboard must not render legacy attachments")
	}
}

func TestTasksCommandUsesNativeWebappPost(t *testing.T) {
	response := &todayResponse{BusinessDate: "2026-08-31"}
	command := tasksCommandResponse(response)
	if command.ResponseType != model.CommandResponseTypeEphemeral || command.Type != postTypeTasks {
		t.Fatalf("unexpected command response: %#v", command)
	}
}
