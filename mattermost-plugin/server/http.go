package main

import (
	"context"
	"encoding/json"
	"net/http"
	"strconv"
	"time"

	"github.com/mattermost/mattermost/server/public/model"
)

type postActionRequest struct {
	UserID    string         `json:"user_id"`
	ChannelID string         `json:"channel_id"`
	Context   map[string]any `json:"context"`
}

func actionContextString(context map[string]any, key string) string {
	value, ok := context[key]
	if !ok {
		return ""
	}
	text, _ := value.(string)
	return text
}

func (p *Plugin) handleTaskAction(writer http.ResponseWriter, request *http.Request) {
	writer.Header().Set("Content-Type", "application/json; charset=utf-8")
	if request.Method != http.MethodPost {
		writer.Header().Set("Allow", http.MethodPost)
		http.Error(writer, `{"error":"method not allowed"}`, http.StatusMethodNotAllowed)
		return
	}
	if request.ContentLength > 32768 {
		http.Error(writer, `{"error":"request too large"}`, http.StatusRequestEntityTooLarge)
		return
	}
	authenticatedUserID := request.Header.Get("Mattermost-User-Id")
	var action postActionRequest
	decoder := json.NewDecoder(http.MaxBytesReader(writer, request.Body, 32768))
	if err := decoder.Decode(&action); err != nil || authenticatedUserID == "" || authenticatedUserID != action.UserID {
		http.Error(writer, `{"error":"invalid action request"}`, http.StatusBadRequest)
		return
	}
	config := p.getConfiguration()
	if config == nil {
		http.Error(writer, `{"error":"plugin is not configured"}`, http.StatusServiceUnavailable)
		return
	}
	user, err := p.mattermostUser(action.UserID)
	if err != nil {
		http.Error(writer, `{"error":"user not found"}`, http.StatusBadRequest)
		return
	}
	taskID, err := strconv.Atoi(actionContextString(action.Context, "task_id"))
	taskAction := actionContextString(action.Context, "task_action")
	expectedVersion := actionContextString(action.Context, "expected_version")
	idempotencyKey := actionContextString(action.Context, "idempotency_key")
	if err != nil || taskID < 1 || taskAction == "" || expectedVersion == "" || idempotencyKey == "" {
		http.Error(writer, `{"error":"invalid task context"}`, http.StatusBadRequest)
		return
	}
	client := newMoedClient(config)
	ctx, cancel := context.WithTimeout(request.Context(), config.timeout())
	defer cancel()
	response, apiErr := client.taskAction(
		ctx,
		user.Id,
		user.Username,
		taskID,
		taskAction,
		expectedVersion,
		idempotencyKey,
	)
	message := "Task action failed. Run `/moed tasks` to refresh."
	if apiErr == nil {
		message = ":white_check_mark: " + response.Message
	} else if typed, ok := apiErr.(*moedAPIError); ok {
		message = ":warning: " + typed.Error()
	}
	p.API.SendEphemeralPost(action.UserID, &model.Post{
		UserId:    p.botID,
		ChannelId: action.ChannelID,
		Message:   message,
		CreateAt:  time.Now().UnixMilli(),
	})
	writer.WriteHeader(http.StatusOK)
	_, _ = writer.Write([]byte(`{}`))
}
