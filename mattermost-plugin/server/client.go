package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	pluginmanifest "github.com/beneliath/moed-mattermost-plugin"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
)

type moedClient struct {
	baseURL    string
	token      string
	instanceID string
	httpClient *http.Client
}

type moedAPIError struct {
	StatusCode int
	Payload    apiError
}

func (e *moedAPIError) Error() string {
	return e.Payload.ErrorMessage()
}

func newMoedClient(config *configuration) *moedClient {
	return &moedClient{
		baseURL:    config.MoedURL,
		token:      config.ServiceToken,
		instanceID: config.InstanceID,
		httpClient: &http.Client{
			Timeout: config.timeout(),
			CheckRedirect: func(_ *http.Request, _ []*http.Request) error {
				return http.ErrUseLastResponse
			},
		},
	}
}

func (c *moedClient) do(
	ctx context.Context,
	method string,
	action string,
	query url.Values,
	userID string,
	username string,
	body any,
	idempotencyKey string,
	out any,
) error {
	endpoint, err := url.Parse(c.baseURL + "/api/v1/mattermost.php")
	if err != nil {
		return fmt.Errorf("build MOED endpoint: %w", err)
	}
	values := endpoint.Query()
	values.Set("action", action)
	for key, entries := range query {
		for _, entry := range entries {
			values.Add(key, entry)
		}
	}
	endpoint.RawQuery = values.Encode()

	var requestBody io.Reader
	if body != nil {
		encoded, encodeErr := json.Marshal(body)
		if encodeErr != nil {
			return fmt.Errorf("encode MOED request: %w", encodeErr)
		}
		requestBody = bytes.NewReader(encoded)
	}
	request, err := http.NewRequestWithContext(ctx, method, endpoint.String(), requestBody)
	if err != nil {
		return fmt.Errorf("create MOED request: %w", err)
	}
	request.Header.Set("Authorization", "Bearer "+c.token)
	request.Header.Set("Accept", "application/json")
	request.Header.Set("User-Agent", "MOED-Mattermost-Plugin/"+pluginmanifest.Version())
	request.Header.Set("X-Mattermost-Instance-ID", c.instanceID)
	if body != nil {
		request.Header.Set("Content-Type", "application/json")
	}
	if userID != "" {
		request.Header.Set("X-Mattermost-User-ID", userID)
	}
	if username != "" {
		request.Header.Set("X-Mattermost-Username", username)
	}
	if idempotencyKey != "" {
		request.Header.Set("Idempotency-Key", idempotencyKey)
	}

	response, err := c.httpClient.Do(request)
	if err != nil {
		return fmt.Errorf("contact MOED: %w", err)
	}
	defer response.Body.Close()
	limited := io.LimitReader(response.Body, 1<<20)
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		payload := apiError{Error: "MOED returned HTTP " + strconv.Itoa(response.StatusCode) + "."}
		_ = json.NewDecoder(limited).Decode(&payload)
		return &moedAPIError{StatusCode: response.StatusCode, Payload: payload}
	}
	if out == nil {
		_, _ = io.Copy(io.Discard, limited)
		return nil
	}
	if err := json.NewDecoder(limited).Decode(out); err != nil {
		return fmt.Errorf("decode MOED response: %w", err)
	}
	return nil
}

func (c *moedClient) status(ctx context.Context) (*statusResponse, error) {
	var response statusResponse
	err := c.do(ctx, http.MethodGet, "status", nil, "", "", nil, "", &response)
	return &response, err
}

func (c *moedClient) me(ctx context.Context, userID, username string) (*userResponse, error) {
	var response userResponse
	err := c.do(ctx, http.MethodGet, "me", nil, userID, username, nil, "", &response)
	return &response, err
}

func (c *moedClient) connect(ctx context.Context, userID, username, code string) (*userResponse, error) {
	var response userResponse
	err := c.do(ctx, http.MethodPost, "connect", nil, userID, username, map[string]string{
		"code": code,
	}, "", &response)
	return &response, err
}

func (c *moedClient) today(ctx context.Context, userID, username string) (*todayResponse, error) {
	var response todayResponse
	err := c.do(ctx, http.MethodGet, "today", nil, userID, username, nil, "", &response)
	return &response, err
}

func (c *moedClient) tasks(ctx context.Context, userID, username string) (*todayResponse, error) {
	var response todayResponse
	err := c.do(ctx, http.MethodGet, "tasks", nil, userID, username, nil, "", &response)
	return &response, err
}

func (c *moedClient) searchEvents(ctx context.Context, userID, username, query string) (*eventSearchResponse, error) {
	var response eventSearchResponse
	values := url.Values{"q": []string{query}}
	err := c.do(ctx, http.MethodGet, "event_search", values, userID, username, nil, "", &response)
	return &response, err
}

func (c *moedClient) event(ctx context.Context, userID, username string, id int) (*eventResponse, error) {
	var response eventResponse
	values := url.Values{"id": []string{strconv.Itoa(id)}}
	err := c.do(ctx, http.MethodGet, "event", values, userID, username, nil, "", &response)
	return &response, err
}

func (c *moedClient) emailCompose(ctx context.Context, userID, username string, engagementID int) (*emailComposeResponse, error) {
	var response emailComposeResponse
	values := url.Values{"id": []string{strconv.Itoa(engagementID)}}
	err := c.do(ctx, http.MethodGet, "email_compose", values, userID, username, nil, "", &response)
	return &response, err
}

func (c *moedClient) sendEmail(
	ctx context.Context,
	userID string,
	username string,
	request sendEmailRequest,
	idempotencyKey string,
) (*emailMessageResponse, error) {
	var response emailMessageResponse
	err := c.do(ctx, http.MethodPost, "email_send", nil, userID, username, request, idempotencyKey, &response)
	return &response, err
}

func (c *moedClient) emailStatus(ctx context.Context, userID, username string, messageID int) (*emailMessageResponse, error) {
	var response emailMessageResponse
	values := url.Values{"id": []string{strconv.Itoa(messageID)}}
	err := c.do(ctx, http.MethodGet, "email_status", values, userID, username, nil, "", &response)
	return &response, err
}

func (c *moedClient) replyNotifications(ctx context.Context) (*replyNotificationsResponse, error) {
	var response replyNotificationsResponse
	err := c.do(ctx, http.MethodGet, "reply_notifications", nil, "", "", nil, "", &response)
	return &response, err
}

func (c *moedClient) acknowledgeReplyNotification(ctx context.Context, notificationID int) error {
	var response replyNotificationAckResponse
	err := c.do(
		ctx,
		http.MethodPost,
		"reply_notification_ack",
		nil,
		"",
		"",
		map[string]int{"notification_id": notificationID},
		"",
		&response,
	)
	if err == nil && !response.OK {
		return fmt.Errorf("MOED did not acknowledge the reply notification")
	}
	return err
}

func (c *moedClient) postReactionNotifications(ctx context.Context) (*postReactionNotificationsResponse, error) {
	var response postReactionNotificationsResponse
	err := c.do(ctx, http.MethodGet, "post_reaction_notifications", nil, "", "", nil, "", &response)
	return &response, err
}

func (c *moedClient) acknowledgePostReactionNotification(ctx context.Context, notificationID int) error {
	var response replyNotificationAckResponse
	err := c.do(
		ctx,
		http.MethodPost,
		"post_reaction_notification_ack",
		nil,
		"",
		"",
		map[string]int{"notification_id": notificationID},
		"",
		&response,
	)
	if err == nil && !response.OK {
		return fmt.Errorf("MOED did not acknowledge the post reaction notification")
	}
	return err
}

func (c *moedClient) failPostReactionNotification(ctx context.Context, notificationID int, failure string) error {
	var response replyNotificationAckResponse
	failure = strings.TrimSpace(failure)
	if failure == "" {
		failure = "Mattermost could not add the reaction."
	}
	failure = truncateRunes(failure, 500)
	err := c.do(
		ctx,
		http.MethodPost,
		"post_reaction_notification_fail",
		nil,
		"",
		"",
		map[string]any{
			"notification_id": notificationID,
			"error":           failure,
		},
		"",
		&response,
	)
	if err == nil && !response.OK {
		return fmt.Errorf("MOED did not defer the post reaction notification")
	}
	return err
}

func (c *moedClient) taskAction(
	ctx context.Context,
	userID string,
	username string,
	taskID int,
	action string,
	expectedVersion string,
	idempotencyKey string,
) (*taskActionResponse, error) {
	var response taskActionResponse
	err := c.do(ctx, http.MethodPost, "task_action", nil, userID, username, map[string]any{
		"task_id":          taskID,
		"task_action":      action,
		"expected_version": expectedVersion,
	}, idempotencyKey, &response)
	return &response, err
}

func (c *moedClient) createTask(
	ctx context.Context,
	userID string,
	username string,
	request createTaskRequest,
	idempotencyKey string,
) (*postActionResponse, error) {
	var response postActionResponse
	err := c.do(ctx, http.MethodPost, "create_task", nil, userID, username, request, idempotencyKey, &response)
	return &response, err
}

func (c *moedClient) saveChron(
	ctx context.Context,
	userID string,
	username string,
	request saveChronRequest,
	idempotencyKey string,
) (*postActionResponse, error) {
	var response postActionResponse
	err := c.do(ctx, http.MethodPost, "save_chron", nil, userID, username, request, idempotencyKey, &response)
	return &response, err
}
