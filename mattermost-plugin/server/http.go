package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/mattermost/mattermost/server/public/model"
)

type interactiveTaskActionRequest struct {
	UserID    string         `json:"user_id"`
	ChannelID string         `json:"channel_id"`
	Context   map[string]any `json:"context"`
}

type webTaskActionRequest struct {
	ChannelID       string `json:"channel_id"`
	TaskID          int    `json:"task_id"`
	TaskAction      string `json:"task_action"`
	ExpectedVersion string `json:"expected_version"`
	IdempotencyKey  string `json:"idempotency_key"`
}

type contextPostActionRequest struct {
	PostID         string `json:"post_id"`
	Action         string `json:"action"`
	Title          string `json:"title"`
	Details        string `json:"details"`
	DueDate        string `json:"due_date"`
	Priority       string `json:"priority"`
	EntryText      string `json:"entry_text"`
	IdempotencyKey string `json:"idempotency_key"`
}

type emailComposeWebRequest struct {
	ChannelID string `json:"channel_id"`
	PostID    string `json:"post_id"`
}

type emailSendWebRequest struct {
	ChannelID         string `json:"channel_id"`
	PostID            string `json:"post_id"`
	ContactIDs        []int  `json:"contact_ids"`
	TemplateKey       string `json:"template_key"`
	Subject           string `json:"subject"`
	Body              string `json:"body"`
	IncludeEventBrief bool   `json:"include_event_brief"`
	IncludePost       bool   `json:"include_post"`
	IncludeThread     bool   `json:"include_thread"`
	IdempotencyKey    string `json:"idempotency_key"`
}

type emailStatusWebRequest struct {
	ChannelID string `json:"channel_id"`
	MessageID int    `json:"message_id"`
}

func writeJSON(writer http.ResponseWriter, status int, payload any) {
	writer.Header().Set("Content-Type", "application/json; charset=utf-8")
	writer.WriteHeader(status)
	_ = json.NewEncoder(writer).Encode(payload)
}

func decodeJSONRequest(writer http.ResponseWriter, request *http.Request, target any) bool {
	if request.Method != http.MethodPost {
		writer.Header().Set("Allow", http.MethodPost)
		writeJSON(writer, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})
		return false
	}
	if request.ContentLength > 32768 {
		writeJSON(writer, http.StatusRequestEntityTooLarge, map[string]string{"error": "request too large"})
		return false
	}
	decoder := json.NewDecoder(http.MaxBytesReader(writer, request.Body, 32768))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "invalid request"})
		return false
	}
	return true
}

func moedErrorStatus(err error) int {
	if typed, ok := err.(*moedAPIError); ok && typed.StatusCode >= 400 && typed.StatusCode <= 599 {
		return typed.StatusCode
	}
	return http.StatusBadGateway
}

func moedErrorPayload(err error) map[string]string {
	if typed, ok := err.(*moedAPIError); ok {
		return map[string]string{"error": typed.Error(), "code": typed.Payload.Code}
	}
	return map[string]string{"error": "MOED is temporarily unavailable."}
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
	authenticatedUserID := request.Header.Get("Mattermost-User-Id")
	var action interactiveTaskActionRequest
	if !decodeJSONRequest(writer, request, &action) {
		return
	}
	if authenticatedUserID == "" || authenticatedUserID != action.UserID {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "invalid action request"})
		return
	}
	config := p.getConfiguration()
	if config == nil {
		writeJSON(writer, http.StatusServiceUnavailable, map[string]string{"error": "plugin is not configured"})
		return
	}
	user, err := p.mattermostUser(action.UserID)
	if err != nil {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "user not found"})
		return
	}
	taskID, err := strconv.Atoi(actionContextString(action.Context, "task_id"))
	taskAction := actionContextString(action.Context, "task_action")
	expectedVersion := actionContextString(action.Context, "expected_version")
	idempotencyKey := actionContextString(action.Context, "idempotency_key")
	if err != nil || taskID < 1 || taskAction == "" || expectedVersion == "" || idempotencyKey == "" {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "invalid task context"})
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
	writeJSON(writer, http.StatusOK, map[string]bool{"ok": apiErr == nil})
}

func (p *Plugin) handleWebTaskAction(writer http.ResponseWriter, request *http.Request) {
	var action webTaskActionRequest
	if !decodeJSONRequest(writer, request, &action) {
		return
	}
	userID := request.Header.Get("Mattermost-User-Id")
	if userID == "" || action.ChannelID == "" || !p.API.HasPermissionToChannel(userID, action.ChannelID, model.PermissionReadChannel) {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": "You cannot access that channel."})
		return
	}
	if action.TaskID < 1 || action.ExpectedVersion == "" || action.IdempotencyKey == "" || !validTaskAction(action.TaskAction) {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Invalid task action."})
		return
	}
	config := p.getConfiguration()
	if config == nil {
		writeJSON(writer, http.StatusServiceUnavailable, map[string]string{"error": "The plugin is not configured."})
		return
	}
	user, err := p.mattermostUser(userID)
	if err != nil {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Mattermost could not resolve your account."})
		return
	}
	ctx, cancel := context.WithTimeout(request.Context(), config.timeout())
	defer cancel()
	response, err := newMoedClient(config).taskAction(
		ctx,
		user.Id,
		user.Username,
		action.TaskID,
		action.TaskAction,
		action.ExpectedVersion,
		action.IdempotencyKey,
	)
	if err != nil {
		writeJSON(writer, moedErrorStatus(err), moedErrorPayload(err))
		return
	}
	writeJSON(writer, http.StatusOK, response)
}

func validTaskAction(action string) bool {
	return action == "assign_to_me" || action == "start" || action == "complete" || action == "reopen"
}

func (p *Plugin) handleChannelBinding(writer http.ResponseWriter, request *http.Request) {
	if request.Method != http.MethodGet {
		writer.Header().Set("Allow", http.MethodGet)
		writeJSON(writer, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})
		return
	}
	userID := request.Header.Get("Mattermost-User-Id")
	channelID := strings.TrimSpace(request.URL.Query().Get("channel_id"))
	if userID == "" || channelID == "" || !p.API.HasPermissionToChannel(userID, channelID, model.PermissionReadChannel) {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": "You cannot access that channel."})
		return
	}
	binding, err := p.channelBinding(channelID)
	if err != nil {
		writeJSON(writer, http.StatusInternalServerError, map[string]string{"error": "The channel binding could not be read."})
		return
	}
	if binding == nil {
		writeJSON(writer, http.StatusOK, channelBindingResponse{Linked: false})
		return
	}
	config := p.getConfiguration()
	if config == nil {
		writeJSON(writer, http.StatusServiceUnavailable, map[string]string{"error": "The plugin is not configured."})
		return
	}
	user, appErr := p.mattermostUser(userID)
	if appErr != nil {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Mattermost could not resolve your account."})
		return
	}
	ctx, cancel := context.WithTimeout(request.Context(), config.timeout())
	defer cancel()
	response, apiErr := newMoedClient(config).event(ctx, user.Id, user.Username, binding.EngagementID)
	if apiErr != nil {
		if typed, ok := apiErr.(*moedAPIError); ok && typed.Payload.Code == "account_not_linked" {
			writeJSON(writer, http.StatusOK, channelBindingResponse{
				Linked: true,
				Engagement: apiEngagement{
					ID:  binding.EngagementID,
					URL: config.MoedURL + "/view_engagement.php?id=" + strconv.Itoa(binding.EngagementID),
				},
			})
			return
		}
		writeJSON(writer, moedErrorStatus(apiErr), moedErrorPayload(apiErr))
		return
	}
	writeJSON(writer, http.StatusOK, channelBindingResponse{
		Linked:     true,
		Engagement: response.Engagement,
		CanEmail:   response.User.Role == "editor" || response.User.Role == "admin",
	})
}

func (p *Plugin) handleEmailCompose(writer http.ResponseWriter, request *http.Request) {
	var compose emailComposeWebRequest
	if !decodeJSONRequest(writer, request, &compose) {
		return
	}
	userID := request.Header.Get("Mattermost-User-Id")
	if userID == "" || compose.ChannelID == "" || !p.API.HasPermissionToChannel(userID, compose.ChannelID, model.PermissionReadChannel) {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": "You cannot access that channel."})
		return
	}
	binding, err := p.channelBinding(compose.ChannelID)
	if err != nil {
		writeJSON(writer, http.StatusInternalServerError, map[string]string{"error": "The channel binding could not be read."})
		return
	}
	if binding == nil {
		writeJSON(writer, http.StatusConflict, map[string]string{"error": "Link this channel to a MOED engagement first with /moed link-event ID."})
		return
	}
	postContext, threadContext, contextErr := p.mattermostEmailContexts(userID, compose.ChannelID, compose.PostID)
	if contextErr != nil {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": contextErr.Error()})
		return
	}
	config := p.getConfiguration()
	if config == nil {
		writeJSON(writer, http.StatusServiceUnavailable, map[string]string{"error": "The plugin is not configured."})
		return
	}
	user, userErr := p.mattermostUser(userID)
	if userErr != nil {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Mattermost could not resolve your account."})
		return
	}
	ctx, cancel := context.WithTimeout(request.Context(), config.timeout())
	defer cancel()
	response, apiErr := newMoedClient(config).emailCompose(ctx, user.Id, user.Username, binding.EngagementID)
	if apiErr != nil {
		writeJSON(writer, moedErrorStatus(apiErr), moedErrorPayload(apiErr))
		return
	}
	response.PostContext = postContext
	response.ThreadContext = threadContext
	writeJSON(writer, http.StatusOK, response)
}

func (p *Plugin) handleEmailSend(writer http.ResponseWriter, request *http.Request) {
	var send emailSendWebRequest
	if !decodeJSONRequest(writer, request, &send) {
		return
	}
	userID := request.Header.Get("Mattermost-User-Id")
	if userID == "" || send.ChannelID == "" || !p.API.HasPermissionToChannel(userID, send.ChannelID, model.PermissionReadChannel) {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": "You cannot access that channel."})
		return
	}
	if len(send.ContactIDs) < 1 || len(send.ContactIDs) > 25 || strings.TrimSpace(send.Subject) == "" || strings.TrimSpace(send.Body) == "" || send.IdempotencyKey == "" {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Choose recipients and complete the email before sending."})
		return
	}
	binding, err := p.channelBinding(send.ChannelID)
	if err != nil {
		writeJSON(writer, http.StatusInternalServerError, map[string]string{"error": "The channel binding could not be read."})
		return
	}
	if binding == nil {
		writeJSON(writer, http.StatusConflict, map[string]string{"error": "Link this channel to a MOED engagement first with /moed link-event ID."})
		return
	}
	postContext, threadContext, contextErr := p.mattermostEmailContexts(userID, send.ChannelID, send.PostID)
	if contextErr != nil {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": contextErr.Error()})
		return
	}
	mattermostContext := ""
	if send.IncludeThread {
		if send.PostID == "" {
			writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Choose a Mattermost post before including its thread."})
			return
		}
		mattermostContext = threadContext
	} else if send.IncludePost {
		if send.PostID == "" {
			writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Choose a Mattermost post before including it."})
			return
		}
		mattermostContext = postContext
	}
	config := p.getConfiguration()
	if config == nil {
		writeJSON(writer, http.StatusServiceUnavailable, map[string]string{"error": "The plugin is not configured."})
		return
	}
	user, userErr := p.mattermostUser(userID)
	if userErr != nil {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Mattermost could not resolve your account."})
		return
	}
	ctx, cancel := context.WithTimeout(request.Context(), config.timeout())
	defer cancel()
	response, apiErr := newMoedClient(config).sendEmail(ctx, user.Id, user.Username, sendEmailRequest{
		EngagementID:      binding.EngagementID,
		ContactIDs:        send.ContactIDs,
		TemplateKey:       strings.TrimSpace(send.TemplateKey),
		Subject:           send.Subject,
		Body:              send.Body,
		IncludeEventBrief: send.IncludeEventBrief,
		MattermostContext: mattermostContext,
	}, send.IdempotencyKey)
	if apiErr != nil {
		writeJSON(writer, moedErrorStatus(apiErr), moedErrorPayload(apiErr))
		return
	}
	writeJSON(writer, http.StatusOK, response)
}

func (p *Plugin) handleEmailStatus(writer http.ResponseWriter, request *http.Request) {
	var status emailStatusWebRequest
	if !decodeJSONRequest(writer, request, &status) {
		return
	}
	userID := request.Header.Get("Mattermost-User-Id")
	if userID == "" || status.ChannelID == "" || status.MessageID < 1 || !p.API.HasPermissionToChannel(userID, status.ChannelID, model.PermissionReadChannel) {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": "You cannot access that delivery record."})
		return
	}
	binding, err := p.channelBinding(status.ChannelID)
	if err != nil || binding == nil {
		writeJSON(writer, http.StatusConflict, map[string]string{"error": "This channel is no longer linked to the outbound message engagement."})
		return
	}
	config := p.getConfiguration()
	if config == nil {
		writeJSON(writer, http.StatusServiceUnavailable, map[string]string{"error": "The plugin is not configured."})
		return
	}
	user, userErr := p.mattermostUser(userID)
	if userErr != nil {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Mattermost could not resolve your account."})
		return
	}
	ctx, cancel := context.WithTimeout(request.Context(), config.timeout())
	defer cancel()
	response, apiErr := newMoedClient(config).emailStatus(ctx, user.Id, user.Username, status.MessageID)
	if apiErr != nil {
		writeJSON(writer, moedErrorStatus(apiErr), moedErrorPayload(apiErr))
		return
	}
	if response.EngagementID != binding.EngagementID {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": "That delivery record belongs to another engagement."})
		return
	}
	writeJSON(writer, http.StatusOK, response)
}

func (p *Plugin) handlePostAction(writer http.ResponseWriter, request *http.Request) {
	var action contextPostActionRequest
	if !decodeJSONRequest(writer, request, &action) {
		return
	}
	userID := request.Header.Get("Mattermost-User-Id")
	if userID == "" {
		writeJSON(writer, http.StatusUnauthorized, map[string]string{"error": "Mattermost could not authenticate this MOED action. Refresh Mattermost and try again."})
		return
	}
	if action.PostID == "" || action.IdempotencyKey == "" || (action.Action != "create_task" && action.Action != "save_chron") {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Invalid MOED post action."})
		return
	}
	post, appErr := p.API.GetPost(action.PostID)
	if appErr != nil || post == nil || post.DeleteAt != 0 || !p.API.HasPermissionToChannel(userID, post.ChannelId, model.PermissionReadChannel) {
		writeJSON(writer, http.StatusForbidden, map[string]string{"error": "You cannot access that post."})
		return
	}
	binding, err := p.channelBinding(post.ChannelId)
	if err != nil {
		writeJSON(writer, http.StatusInternalServerError, map[string]string{"error": "The channel binding could not be read."})
		return
	}
	if binding == nil {
		writeJSON(writer, http.StatusConflict, map[string]string{"error": "Link this channel to a MOED engagement first with /moed link-event ID."})
		return
	}
	config := p.getConfiguration()
	if config == nil {
		writeJSON(writer, http.StatusServiceUnavailable, map[string]string{"error": "The plugin is not configured."})
		return
	}
	user, err := p.mattermostUser(userID)
	if err != nil {
		writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Mattermost could not resolve your account."})
		return
	}
	source := p.postSource(post)
	ctx, cancel := context.WithTimeout(request.Context(), config.timeout())
	defer cancel()
	client := newMoedClient(config)
	var response *postActionResponse
	if action.Action == "create_task" {
		title := strings.TrimSpace(action.Title)
		details := strings.TrimSpace(action.Details)
		if title == "" || len(title) > 255 || len(details) > 18000 {
			writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Enter a task title and keep the notes within the allowed length."})
			return
		}
		response, err = client.createTask(ctx, user.Id, user.Username, createTaskRequest{
			EngagementID: binding.EngagementID,
			Title:        title,
			Details:      joinSourceText(details, source),
			DueDate:      strings.TrimSpace(action.DueDate),
			Priority:     strings.TrimSpace(action.Priority),
		}, action.IdempotencyKey)
	} else {
		entryText := strings.TrimSpace(action.EntryText)
		if entryText == "" || len(entryText) > 24000 {
			writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "Enter a Chron note within the allowed length."})
			return
		}
		response, err = client.saveChron(ctx, user.Id, user.Username, saveChronRequest{
			EngagementID: binding.EngagementID,
			EntryText:    joinSourceText(entryText, source),
		}, action.IdempotencyKey)
	}
	if err != nil {
		writeJSON(writer, moedErrorStatus(err), moedErrorPayload(err))
		return
	}
	writeJSON(writer, http.StatusOK, response)
}

func joinSourceText(text, source string) string {
	if text == "" {
		return source
	}
	return text + "\n\n" + source
}

func (p *Plugin) mattermostEmailContexts(userID, channelID, postID string) (string, string, error) {
	postID = strings.TrimSpace(postID)
	if postID == "" {
		return "", "", nil
	}
	post, appErr := p.API.GetPost(postID)
	if appErr != nil || post == nil || post.DeleteAt != 0 || post.ChannelId != channelID || !p.API.HasPermissionToChannel(userID, channelID, model.PermissionReadChannel) {
		return "", "", fmt.Errorf("You cannot include that Mattermost post")
	}
	selected := "MATTERMOST POST\n" + p.postSource(post) + "\n\nMessage:\n" + strings.TrimSpace(post.Message)
	selected = truncateRunes(selected, 12000)

	thread, threadErr := p.API.GetPostThread(postID)
	if threadErr != nil || thread == nil {
		return selected, selected, nil
	}
	lines := []string{"MATTERMOST THREAD", p.postSource(post), ""}
	count := 0
	for _, id := range thread.Order {
		entry := thread.Posts[id]
		if entry == nil || entry.DeleteAt != 0 || entry.ChannelId != channelID || strings.TrimSpace(entry.Message) == "" {
			continue
		}
		author := "unknown user"
		if entry.UserId == p.botID {
			author = "MOED"
		} else if user, lookupErr := p.API.GetUser(entry.UserId); lookupErr == nil && user != nil {
			author = "@" + user.Username
		}
		stamp := time.UnixMilli(entry.CreateAt).UTC().Format("2006-01-02 15:04 UTC")
		lines = append(lines, author+" · "+stamp, strings.TrimSpace(entry.Message), "")
		count++
		if count >= 20 || utf8Length(strings.Join(lines, "\n")) >= 18000 {
			break
		}
	}
	if count == 0 {
		return selected, selected, nil
	}
	return selected, truncateRunes(strings.Join(lines, "\n"), 20000), nil
}

func utf8Length(value string) int {
	return len([]rune(value))
}

func (p *Plugin) postSource(post *model.Post) string {
	author := "unknown user"
	if user, appErr := p.API.GetUser(post.UserId); appErr == nil && user != nil {
		author = "@" + user.Username
	}
	channelName := post.ChannelId
	permalink := ""
	if channel, appErr := p.API.GetChannel(post.ChannelId); appErr == nil && channel != nil {
		if channel.DisplayName != "" {
			channelName = channel.DisplayName
		} else if channel.Name != "" {
			channelName = channel.Name
		}
		if channel.TeamId != "" {
			if team, teamErr := p.API.GetTeam(channel.TeamId); teamErr == nil && team != nil {
				config := p.API.GetConfig()
				if config != nil && config.ServiceSettings.SiteURL != nil {
					siteURL := strings.TrimRight(*config.ServiceSettings.SiteURL, "/")
					if siteURL != "" {
						permalink = siteURL + "/" + team.Name + "/pl/" + post.Id
					}
				}
			}
		}
	}
	lines := []string{
		"Mattermost source",
		"Author: " + author,
		"Channel: " + channelName,
		"Post ID: " + post.Id,
	}
	if permalink != "" {
		lines = append(lines, "Post: "+permalink)
	}
	return strings.Join(lines, "\n")
}
