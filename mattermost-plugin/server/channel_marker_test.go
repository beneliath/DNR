package main

import (
	"encoding/json"
	"strings"
	"testing"
	"unicode/utf8"

	"github.com/mattermost/mattermost/server/public/model"
	"github.com/mattermost/mattermost/server/public/plugin/plugintest"
	"github.com/stretchr/testify/mock"
)

func TestChannelDisplayNameWithMarkerReplacesExistingMarkerAndFits(t *testing.T) {
	name := channelDisplayNameWithMarker(
		"[MOED#2.BBBBBBBBBBBBBBBBBBBBBB] "+strings.Repeat("é", 64),
		"[MOED#17.AAAAAAAAAAAAAAAAAAAAAA]",
	)
	if !strings.HasPrefix(name, "[MOED#17] ") {
		t.Fatalf("expected the compact new marker, got %q", name)
	}
	if strings.Contains(name, "MOED#2") {
		t.Fatalf("expected the old marker to be removed, got %q", name)
	}
	if strings.Contains(name, "AAAAAAAAAAAAAAAAAAAAAA") {
		t.Fatalf("expected the signed token to be omitted from the channel display name, got %q", name)
	}
	if utf8.RuneCountInString(name) > model.ChannelDisplayNameMaxRunes {
		t.Fatalf("marked display name exceeds Mattermost's limit: %d", utf8.RuneCountInString(name))
	}
}

func TestLinkedChannelNamesPreservesOriginalAcrossRelink(t *testing.T) {
	original := strings.Repeat("A", model.ChannelDisplayNameMaxRunes)
	firstLinked := channelDisplayNameWithMarker(original, "[MOED#1.AAAAAAAAAAAAAAAAAAAAAA]")
	existing := &channelBinding{
		OriginalChannelDisplayName: original,
		AppliedChannelDisplayName:  firstLinked,
	}

	storedOriginal, nextLinked := linkedChannelNames(firstLinked, existing, "[MOED#2.BBBBBBBBBBBBBBBBBBBBBB]")
	if storedOriginal != original {
		t.Fatalf("expected the full original name to survive relinking, got %q", storedOriginal)
	}
	if !strings.HasPrefix(nextLinked, "[MOED#2] ") {
		t.Fatalf("expected replacement marker, got %q", nextLinked)
	}
}

func TestUnlinkedChannelDisplayNameRestoresOrPreservesRename(t *testing.T) {
	binding := &channelBinding{
		OriginalChannelDisplayName: "Original channel title",
		AppliedChannelDisplayName:  "[MOED#8] Original channel title",
	}
	if got := unlinkedChannelDisplayName(binding.AppliedChannelDisplayName, binding); got != binding.OriginalChannelDisplayName {
		t.Fatalf("expected the original title, got %q", got)
	}
	if got := unlinkedChannelDisplayName("[MOED#8] Manually renamed", binding); got != "Manually renamed" {
		t.Fatalf("expected the manual rename without the marker, got %q", got)
	}
}

func TestUserCanManageLinkedChannelRequiresNativePermissions(t *testing.T) {
	tests := []struct {
		name        string
		channelType model.ChannelType
		permission  *model.Permission
	}{
		{name: "public", channelType: model.ChannelTypeOpen, permission: model.PermissionManagePublicChannelProperties},
		{name: "private", channelType: model.ChannelTypePrivate, permission: model.PermissionManagePrivateChannelProperties},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			api := &plugintest.API{}
			channel := &model.Channel{Id: "channel-id", Type: test.channelType}
			api.On("HasPermissionToChannel", "user-id", channel.Id, test.permission).Return(true).Once()
			api.On("HasPermissionToChannel", "user-id", channel.Id, model.PermissionCreatePost).Return(true).Once()
			defer api.AssertExpectations(t)

			plugin := &Plugin{}
			plugin.SetAPI(api)
			if !plugin.userCanManageLinkedChannel("user-id", channel) {
				t.Fatal("expected a user with both native permissions to manage the linked channel")
			}
		})
	}
}

func TestUserCanManageLinkedChannelDeniesMissingManagePermission(t *testing.T) {
	api := &plugintest.API{}
	channel := &model.Channel{Id: "channel-id", Type: model.ChannelTypeOpen}
	api.On(
		"HasPermissionToChannel",
		"user-id",
		channel.Id,
		model.PermissionManagePublicChannelProperties,
	).Return(false).Once()
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	if plugin.userCanManageLinkedChannel("user-id", channel) {
		t.Fatal("expected a user without native channel-management permission to be denied")
	}
}

func TestUserCanManageLinkedChannelDeniesMissingPostPermission(t *testing.T) {
	api := &plugintest.API{}
	channel := &model.Channel{Id: "channel-id", Type: model.ChannelTypePrivate}
	api.On(
		"HasPermissionToChannel",
		"user-id",
		channel.Id,
		model.PermissionManagePrivateChannelProperties,
	).Return(true).Once()
	api.On("HasPermissionToChannel", "user-id", channel.Id, model.PermissionCreatePost).Return(false).Once()
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	if plugin.userCanManageLinkedChannel("user-id", channel) {
		t.Fatal("expected a user without create-post permission to be denied")
	}
}

func TestReconcileChannelBindingMarkersUpgradesExistingBinding(t *testing.T) {
	api := &plugintest.API{}
	marker := "[MOED#17.AAAAAAAAAAAAAAAAAAAAAA]"
	legacyBinding, err := json.Marshal(channelBinding{
		EngagementID: 17, LinkedBy: "user-id", LinkedAt: 1234, EmailRoutingMarker: marker,
	})
	if err != nil {
		t.Fatal(err)
	}
	api.On("KVList", 0, 100).Return([]string{"unrelated", "channel_binding:channel-id"}, nil)
	api.On("KVGet", "channel_binding:channel-id").Return(legacyBinding, nil)
	api.On("GetChannel", "channel-id").Return(&model.Channel{Id: "channel-id", DisplayName: "Event team"}, nil).Twice()
	api.On("UpdateChannel", mock.MatchedBy(func(channel *model.Channel) bool {
		return channel.Id == "channel-id" && channel.DisplayName == "[MOED#17] Event team"
	})).Return(&model.Channel{Id: "channel-id", DisplayName: "[MOED#17] Event team"}, nil)
	api.On("KVSet", "channel_binding:channel-id", mock.MatchedBy(func(encoded []byte) bool {
		var binding channelBinding
		return json.Unmarshal(encoded, &binding) == nil &&
			binding.EmailRoutingMarker == marker &&
			binding.OriginalChannelDisplayName == "Event team" &&
			binding.AppliedChannelDisplayName == "[MOED#17] Event team"
	})).Return(nil)
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	plugin.reconcileChannelBindingMarkers()
}

func TestReconcileChannelBindingMarkersMigratesSignedStoredName(t *testing.T) {
	api := &plugintest.API{}
	marker := "[MOED#17.AAAAAAAAAAAAAAAAAAAAAA]"
	previousDisplayName := marker + " Event team"
	storedBinding, err := json.Marshal(channelBinding{
		EngagementID:               17,
		LinkedBy:                   "user-id",
		LinkedAt:                   1234,
		EmailRoutingMarker:         marker,
		OriginalChannelDisplayName: "Event team",
		AppliedChannelDisplayName:  previousDisplayName,
	})
	if err != nil {
		t.Fatal(err)
	}
	api.On("KVList", 0, 100).Return([]string{"channel_binding:channel-id"}, nil)
	api.On("KVGet", "channel_binding:channel-id").Return(storedBinding, nil)
	api.On("GetChannel", "channel-id").Return(&model.Channel{Id: "channel-id", DisplayName: previousDisplayName}, nil).Twice()
	api.On("UpdateChannel", mock.MatchedBy(func(channel *model.Channel) bool {
		return channel.Id == "channel-id" && channel.DisplayName == "[MOED#17] Event team"
	})).Return(&model.Channel{Id: "channel-id", DisplayName: "[MOED#17] Event team"}, nil)
	api.On("KVSet", "channel_binding:channel-id", mock.MatchedBy(func(encoded []byte) bool {
		var binding channelBinding
		return json.Unmarshal(encoded, &binding) == nil &&
			binding.EmailRoutingMarker == marker &&
			binding.OriginalChannelDisplayName == "Event team" &&
			binding.AppliedChannelDisplayName == "[MOED#17] Event team"
	})).Return(nil)
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	plugin.reconcileChannelBindingMarkers()
}

func TestReconcileChannelBindingMarkersDoesNotFabricateUnsignedMarker(t *testing.T) {
	api := &plugintest.API{}
	legacyBinding, err := json.Marshal(channelBinding{EngagementID: 17, LinkedBy: "user-id", LinkedAt: 1234})
	if err != nil {
		t.Fatal(err)
	}
	api.On("KVList", 0, 100).Return([]string{"channel_binding:channel-id"}, nil)
	api.On("KVGet", "channel_binding:channel-id").Return(legacyBinding, nil)
	defer api.AssertExpectations(t)

	plugin := &Plugin{}
	plugin.SetAPI(api)
	plugin.reconcileChannelBindingMarkers()
}
