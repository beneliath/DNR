package main

import (
	"fmt"
	"strings"
	"unicode/utf8"

	"github.com/mattermost/mattermost/server/public/model"
)

func escapeMarkdown(value string) string {
	replacer := strings.NewReplacer(
		"\\", "\\\\",
		"*", "\\*",
		"_", "\\_",
		"~", "\\~",
		"[", "\\[",
		"]", "\\]",
		"`", "\\`",
	)
	return replacer.Replace(strings.TrimSpace(value))
}

func truncateRunes(value string, maximum int) string {
	if utf8.RuneCountInString(value) <= maximum {
		return value
	}
	runes := []rune(value)
	return strings.TrimSpace(string(runes[:maximum-1])) + "…"
}

func pointerText(value *string) string {
	if value == nil {
		return ""
	}
	return strings.TrimSpace(*value)
}

func formatDateRange(start string, end *string) string {
	finish := pointerText(end)
	if finish == "" || finish == start {
		return start
	}
	return start + " – " + finish
}

func formatStatus(value string) string {
	return strings.Title(strings.ReplaceAll(value, "_", " ")) //nolint:staticcheck
}

func taskColor(priority string) string {
	switch priority {
	case "urgent":
		return "#d24b4e"
	case "high":
		return "#e09b39"
	default:
		return "#2457d6"
	}
}

func taskAttachment(task apiTask) *model.SlackAttachment {
	due := "No due date"
	if task.DueDate != nil && *task.DueDate != "" {
		due = *task.DueDate
	}
	fields := []*model.SlackAttachmentField{
		{Title: "Status", Value: formatStatus(task.Status), Short: true},
		{Title: "Due", Value: due, Short: true},
		{Title: "Priority", Value: formatStatus(task.Priority), Short: true},
		{Title: "Record", Value: escapeMarkdown(task.Subject), Short: true},
	}
	attachment := &model.SlackAttachment{
		Fallback:  task.Title,
		Color:     taskColor(task.Priority),
		Title:     escapeMarkdown(task.Title),
		TitleLink: task.URL,
		Text:      escapeMarkdown(truncateRunes(task.Details, 500)),
		Fields:    fields,
	}
	for _, action := range task.AllowedActions {
		name := map[string]string{
			"assign_to_me": "Assign to me",
			"start":        "Start",
			"complete":     "Complete",
			"reopen":       "Reopen",
		}[action]
		if name == "" {
			continue
		}
		style := "default"
		if action == "complete" {
			style = "primary"
		}
		attachment.Actions = append(attachment.Actions, &model.PostAction{
			Id:    "moed-task-" + action,
			Name:  name,
			Type:  model.PostActionTypeButton,
			Style: style,
			Integration: &model.PostActionIntegration{
				URL: "/plugins/" + pluginID + "/api/v1/task-action",
				Context: map[string]any{
					"task_id":          fmt.Sprintf("%d", task.ID),
					"task_action":      action,
					"expected_version": task.UpdatedAt,
					"idempotency_key":  model.NewId(),
				},
			},
		})
	}
	return attachment
}

func eventAddress(event apiEngagement) string {
	parts := make([]string, 0, 5)
	for _, value := range []*string{event.AddressLine1, event.AddressLine2, event.City, event.State, event.PostalCode, event.Country} {
		if text := pointerText(value); text != "" {
			parts = append(parts, text)
		}
	}
	return strings.Join(parts, ", ")
}

func eventAttachment(event apiEngagement) *model.SlackAttachment {
	fields := []*model.SlackAttachmentField{
		{Title: "Organization", Value: escapeMarkdown(event.OrganizationName), Short: true},
		{Title: "Dates", Value: formatDateRange(event.EventStartDate, event.EventEndDate), Short: true},
		{Title: "Lifecycle", Value: formatStatus(event.LifecycleStatus), Short: true},
		{Title: "Confirmation", Value: formatStatus(event.ConfirmationStatus), Short: true},
	}
	if address := eventAddress(event); address != "" {
		fields = append(fields, &model.SlackAttachmentField{Title: "Location", Value: escapeMarkdown(address), Short: false})
	}
	work := fmt.Sprintf(
		"%d active · %d overdue · %d unassigned",
		event.TaskSummary.Active,
		event.TaskSummary.Overdue,
		event.TaskSummary.Unassigned,
	)
	fields = append(fields, &model.SlackAttachmentField{Title: "Follow-up", Value: work, Short: false})

	presentations := make([]string, 0, len(event.Presentations))
	for _, presentation := range event.Presentations {
		when := strings.TrimSpace(pointerText(presentation.PresentationDate) + " " + pointerText(presentation.PresentationTime))
		line := "• " + escapeMarkdown(presentation.TopicTitle)
		if when != "" {
			line += " — " + when
		}
		if presentation.SpeakerName != "" {
			line += " · " + escapeMarkdown(presentation.SpeakerName)
		}
		presentations = append(presentations, line)
	}
	if len(presentations) > 0 {
		fields = append(fields, &model.SlackAttachmentField{
			Title: "Presentations",
			Value: strings.Join(presentations, "\n"),
			Short: false,
		})
	}

	return &model.SlackAttachment{
		Fallback:  event.Title,
		Color:     "#0f766e",
		Title:     escapeMarkdown(event.Title),
		TitleLink: event.URL,
		Text:      escapeMarkdown(truncateRunes(event.EventDescription, 700)),
		Fields:    fields,
		Footer:    "MOED is the system of record · Open the title for the full record",
	}
}

func attachmentsProps(attachments ...*model.SlackAttachment) model.StringInterface {
	return model.StringInterface{"attachments": attachments}
}
