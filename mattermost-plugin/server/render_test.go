package main

import (
	"fmt"
	"strings"
	"testing"
)

func TestTaskAttachmentContainsOnlyAllowedActions(t *testing.T) {
	task := apiTask{
		ID:             17,
		Title:          "Confirm venue",
		Status:         "open",
		Priority:       "high",
		Subject:        "Spring gathering",
		UpdatedAt:      "2026-08-31 12:00:00",
		URL:            "https://moed.example.test/tasks.php?id=17",
		AllowedActions: []string{"assign_to_me", "complete", "delete"},
	}
	attachment := taskAttachment(task)
	if len(attachment.Actions) != 2 {
		t.Fatalf("expected two safe actions, got %d", len(attachment.Actions))
	}
	for _, action := range attachment.Actions {
		if action.Name == "delete" {
			t.Fatal("destructive action must not be rendered")
		}
		if action.Integration == nil || action.Integration.URL != "/plugins/org.moed.mattermost/api/v1/task-action" {
			t.Fatal("unexpected action integration")
		}
	}
}

func TestEventAttachmentIncludesRoutingMarker(t *testing.T) {
	event := apiEngagement{
		ID:                 44,
		Title:              "Spring gathering",
		EventDescription:   "Public description",
		EventStartDate:     "2026-09-10",
		ConfirmationStatus: "confirmed",
		LifecycleStatus:    "active",
		OrganizationName:   "Example Organization",
		URL:                "https://moed.example.test/view_engagement.php?id=44",
		EmailRoutingMarker: "[MOED#44]",
	}
	attachment := eventAttachment(event)
	if attachment.TitleLink != event.URL || attachment.Text != "Public description" {
		t.Fatal("engagement card did not render expected safe fields")
	}
	if len(attachment.Actions) != 0 {
		t.Fatal("engagement card should not contain mutation actions")
	}
	foundMarker := false
	for _, field := range attachment.Fields {
		if field != nil && field.Title == "Email routing marker" && strings.Contains(fmt.Sprint(field.Value), "MOED#44") {
			foundMarker = true
		}
	}
	if !foundMarker {
		t.Fatal("fallback engagement cards must display the email routing marker")
	}
}
