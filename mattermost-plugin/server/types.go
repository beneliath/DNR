package main

type apiUser struct {
	ID          int    `json:"id"`
	Username    string `json:"username"`
	DisplayName string `json:"display_name"`
	Role        string `json:"role"`
}

type apiTask struct {
	ID             int      `json:"id"`
	Title          string   `json:"title"`
	Details        string   `json:"details"`
	Status         string   `json:"status"`
	Priority       string   `json:"priority"`
	DueDate        *string  `json:"due_date"`
	WaitingOn      *string  `json:"waiting_on"`
	Assignee       *string  `json:"assignee"`
	Subject        string   `json:"subject"`
	UpdatedAt      string   `json:"updated_at"`
	URL            string   `json:"url"`
	AllowedActions []string `json:"allowed_actions"`
}

type apiPresentation struct {
	TopicTitle       string  `json:"topic_title"`
	PresentationDate *string `json:"presentation_date"`
	PresentationTime *string `json:"presentation_time"`
	SpeakerName      string  `json:"speaker_name"`
}

type apiTaskSummary struct {
	Active     int `json:"active"`
	Overdue    int `json:"overdue"`
	Unassigned int `json:"unassigned"`
}

type apiTaskDashboardSummary struct {
	Overdue       int `json:"overdue"`
	DueToday      int `json:"due_today"`
	NextSevenDays int `json:"next_seven_days"`
	Waiting       int `json:"waiting"`
}

type apiEngagement struct {
	ID                 int               `json:"id"`
	Title              string            `json:"title"`
	EventDescription   string            `json:"event_description"`
	EventStartDate     string            `json:"event_start_date"`
	EventEndDate       *string           `json:"event_end_date"`
	EventType          string            `json:"event_type"`
	EventTypeOther     *string           `json:"event_type_other"`
	ConfirmationStatus string            `json:"confirmation_status"`
	LifecycleStatus    string            `json:"lifecycle_status"`
	AddressLine1       *string           `json:"event_address_line_1"`
	AddressLine2       *string           `json:"event_address_line_2"`
	City               *string           `json:"event_city"`
	State              *string           `json:"event_state"`
	PostalCode         *string           `json:"event_zipcode"`
	Country            *string           `json:"event_country"`
	OrganizationName   string            `json:"organization_name"`
	URL                string            `json:"url"`
	EmailRoutingMarker string            `json:"email_routing_marker"`
	Presentations      []apiPresentation `json:"presentations"`
	TaskSummary        apiTaskSummary    `json:"task_summary"`
}

type apiError struct {
	Error     string `json:"error"`
	Code      string `json:"code"`
	RequestID string `json:"request_id"`
}

func (e *apiError) ErrorMessage() string {
	if e.Error == "" {
		return "MOED returned an unexpected error."
	}
	return e.Error
}

type statusResponse struct {
	OK                 bool   `json:"ok"`
	IntegrationVersion string `json:"integration_version"`
	Application        string `json:"application"`
	InstanceID         string `json:"instance_id"`
}

type userResponse struct {
	OK      bool    `json:"ok"`
	Message string  `json:"message"`
	User    apiUser `json:"user"`
}

type todayResponse struct {
	OK           bool                    `json:"ok"`
	User         apiUser                 `json:"user"`
	BusinessDate string                  `json:"business_date"`
	TaskSummary  apiTaskDashboardSummary `json:"task_summary"`
	Tasks        []apiTask               `json:"tasks"`
	Engagements  []apiEngagement         `json:"engagements"`
	DashboardURL string                  `json:"dashboard_url"`
	WorkQueueURL string                  `json:"work_queue_url"`
}

type eventSearchResponse struct {
	OK          bool            `json:"ok"`
	User        apiUser         `json:"user"`
	Query       string          `json:"query"`
	Engagements []apiEngagement `json:"engagements"`
}

type eventResponse struct {
	OK         bool          `json:"ok"`
	User       apiUser       `json:"user"`
	Engagement apiEngagement `json:"engagement"`
}

type taskActionResponse struct {
	OK      bool    `json:"ok"`
	Message string  `json:"message"`
	User    apiUser `json:"user"`
	Task    apiTask `json:"task"`
}

type createTaskRequest struct {
	EngagementID int    `json:"engagement_id"`
	Title        string `json:"title"`
	Details      string `json:"details"`
	DueDate      string `json:"due_date"`
	Priority     string `json:"priority"`
}

type saveChronRequest struct {
	EngagementID     int    `json:"engagement_id"`
	EntryText        string `json:"entry_text"`
	MattermostPostID string `json:"mattermost_post_id,omitempty"`
}

type postActionResponse struct {
	OK      bool     `json:"ok"`
	Message string   `json:"message"`
	User    apiUser  `json:"user"`
	Task    *apiTask `json:"task,omitempty"`
	URL     string   `json:"url,omitempty"`
}

type apiEmailContact struct {
	ID         int      `json:"id"`
	Name       string   `json:"name"`
	Email      string   `json:"email"`
	Roles      []string `json:"roles"`
	RoleLabels []string `json:"role_labels"`
}

type apiEmailTemplate struct {
	Key                 string `json:"key"`
	Label               string `json:"label"`
	Subject             string `json:"subject"`
	Body                string `json:"body"`
	SuggestedContactIDs []int  `json:"suggested_contact_ids"`
}

type emailComposeResponse struct {
	OK                bool                        `json:"ok"`
	User              apiUser                     `json:"user"`
	Engagement        apiEngagement               `json:"engagement"`
	Contacts          []apiEmailContact           `json:"contacts"`
	Templates         map[string]apiEmailTemplate `json:"templates"`
	SafeEventBrief    string                      `json:"safe_event_brief"`
	DeliveryAvailable bool                        `json:"delivery_available"`
	ComposeURL        string                      `json:"compose_url"`
	PostContext       string                      `json:"post_context,omitempty"`
	ThreadContext     string                      `json:"thread_context,omitempty"`
}

type sendEmailRequest struct {
	EngagementID      int    `json:"engagement_id"`
	ContactIDs        []int  `json:"contact_ids"`
	TemplateKey       string `json:"template_key"`
	Subject           string `json:"subject"`
	Body              string `json:"body"`
	IncludeEventBrief bool   `json:"include_event_brief"`
	MattermostContext string `json:"mattermost_context"`
	MattermostPostID  string `json:"mattermost_post_id,omitempty"`
}

type apiEmailDelivery struct {
	Name      string `json:"name"`
	Status    string `json:"status"`
	LastError string `json:"last_error"`
}

type emailMessageResponse struct {
	OK             bool               `json:"ok"`
	Message        string             `json:"message"`
	MessageID      int                `json:"message_id"`
	EngagementID   int                `json:"engagement_id"`
	Status         string             `json:"status"`
	RecipientCount int                `json:"recipient_count"`
	SentCount      int                `json:"sent_count"`
	FailedCount    int                `json:"failed_count"`
	PendingCount   int                `json:"pending_count"`
	Deliveries     []apiEmailDelivery `json:"deliveries"`
	URL            string             `json:"url"`
}

type replyNotification struct {
	ID               int    `json:"id"`
	MattermostUserID string `json:"mattermost_user_id"`
	EngagementID     int    `json:"engagement_id"`
	EngagementTitle  string `json:"engagement_title"`
	SenderName       string `json:"sender_name"`
	SenderAddress    string `json:"sender_address"`
	Subject          string `json:"subject"`
	ReceivedAt       string `json:"received_at"`
	AttachmentCount  int    `json:"attachment_count"`
	URL              string `json:"url"`
}

type replyNotificationsResponse struct {
	OK            bool                `json:"ok"`
	Notifications []replyNotification `json:"notifications"`
}

type postReactionNotification struct {
	ID                     int    `json:"id"`
	MattermostPostID       string `json:"mattermost_post_id"`
	OutboundEmailMessageID int    `json:"outbound_email_message_id"`
	ReactionName           string `json:"reaction_name"`
}

type postReactionNotificationsResponse struct {
	OK            bool                       `json:"ok"`
	Notifications []postReactionNotification `json:"notifications"`
}

type replyNotificationAckResponse struct {
	OK bool `json:"ok"`
}

type channelBindingResponse struct {
	Linked     bool          `json:"linked"`
	Engagement apiEngagement `json:"engagement"`
	CanEmail   bool          `json:"can_email"`
}

type channelBinding struct {
	EngagementID               int    `json:"engagement_id"`
	LinkedBy                   string `json:"linked_by"`
	LinkedAt                   int64  `json:"linked_at"`
	EmailRoutingMarker         string `json:"email_routing_marker,omitempty"`
	OriginalChannelDisplayName string `json:"original_channel_display_name,omitempty"`
	AppliedChannelDisplayName  string `json:"applied_channel_display_name,omitempty"`
}
