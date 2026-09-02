package main

import (
	"regexp"
	"strings"
	"unicode/utf8"

	"github.com/mattermost/mattermost/server/public/model"
)

var moedChannelMarkerPrefix = regexp.MustCompile(`^\s*\[MOED#[0-9]+(?:\.[A-Za-z0-9_-]{22})?\]\s*`)
var signedMOEDChannelMarker = regexp.MustCompile(`^\[MOED#([0-9]+)\.[A-Za-z0-9_-]{22}\]$`)

func isSignedMOEDChannelMarker(marker string) bool {
	return signedMOEDChannelMarker.MatchString(strings.TrimSpace(marker))
}

func compactMOEDChannelMarker(marker string) string {
	trimmed := strings.TrimSpace(marker)
	match := signedMOEDChannelMarker.FindStringSubmatch(trimmed)
	if len(match) != 2 {
		return trimmed
	}
	return "[MOED#" + match[1] + "]"
}

func stripMOEDChannelMarker(displayName string) string {
	return strings.TrimSpace(moedChannelMarkerPrefix.ReplaceAllString(displayName, ""))
}

func truncateChannelNameRunes(value string, limit int) string {
	if limit <= 0 {
		return ""
	}
	runes := []rune(value)
	if len(runes) <= limit {
		return value
	}
	return string(runes[:limit])
}

func channelDisplayNameWithMarker(displayName, marker string) string {
	base := stripMOEDChannelMarker(displayName)
	prefix := compactMOEDChannelMarker(marker)
	if prefix == "" {
		return base
	}
	prefix += " "
	available := model.ChannelDisplayNameMaxRunes - utf8.RuneCountInString(prefix)
	return prefix + truncateChannelNameRunes(base, available)
}

func linkedChannelNames(current string, existing *channelBinding, marker string) (original string, linked string) {
	original = stripMOEDChannelMarker(current)
	if existing != nil && existing.AppliedChannelDisplayName != "" && current == existing.AppliedChannelDisplayName && existing.OriginalChannelDisplayName != "" {
		original = existing.OriginalChannelDisplayName
	}
	return original, channelDisplayNameWithMarker(original, marker)
}

func unlinkedChannelDisplayName(current string, binding *channelBinding) string {
	if binding != nil && binding.AppliedChannelDisplayName != "" && current == binding.AppliedChannelDisplayName && binding.OriginalChannelDisplayName != "" {
		return binding.OriginalChannelDisplayName
	}
	return stripMOEDChannelMarker(current)
}

func (p *Plugin) userCanManageLinkedChannel(userID string, channel *model.Channel) bool {
	if userID == "" || channel == nil || channel.Id == "" {
		return false
	}
	var managePermission *model.Permission
	switch channel.Type {
	case model.ChannelTypeOpen:
		managePermission = model.PermissionManagePublicChannelProperties
	case model.ChannelTypePrivate:
		managePermission = model.PermissionManagePrivateChannelProperties
	default:
		return false
	}
	return p.API.HasPermissionToChannel(userID, channel.Id, managePermission) &&
		p.API.HasPermissionToChannel(userID, channel.Id, model.PermissionCreatePost)
}

func (p *Plugin) updateChannelDisplayName(channelID, displayName string) error {
	channel, appErr := p.API.GetChannel(channelID)
	if appErr != nil {
		return appErr
	}
	if channel.DisplayName == displayName {
		return nil
	}
	channel.DisplayName = displayName
	if _, appErr = p.API.UpdateChannel(channel); appErr != nil {
		return appErr
	}
	return nil
}

func (p *Plugin) reconcileChannelBindingMarkers() {
	const pageSize = 100
	for page := 0; ; page++ {
		keys, appErr := p.API.KVList(page, pageSize)
		if appErr != nil {
			p.API.LogWarn("Could not list MOED channel bindings for marker reconciliation", "error", appErr.Error())
			return
		}
		for _, key := range keys {
			if !strings.HasPrefix(key, "channel_binding:") {
				continue
			}
			channelID := strings.TrimPrefix(key, "channel_binding:")
			binding, err := p.channelBinding(channelID)
			if err != nil || binding == nil {
				p.API.LogWarn("Could not read MOED channel binding during marker reconciliation", "channel_id", channelID)
				continue
			}
			marker := strings.TrimSpace(binding.EmailRoutingMarker)
			if !isSignedMOEDChannelMarker(marker) {
				continue
			}
			channel, channelErr := p.API.GetChannel(channelID)
			if channelErr != nil || channel == nil {
				p.API.LogWarn("Could not read linked Mattermost channel during marker reconciliation", "channel_id", channelID)
				continue
			}
			previousDisplayName := channel.DisplayName
			originalDisplayName, linkedDisplayName := linkedChannelNames(previousDisplayName, binding, marker)
			if err := p.updateChannelDisplayName(channelID, linkedDisplayName); err != nil {
				p.API.LogWarn("Could not add the MOED marker to a linked Mattermost channel", "channel_id", channelID, "error", err.Error())
				continue
			}
			binding.EmailRoutingMarker = marker
			binding.OriginalChannelDisplayName = originalDisplayName
			binding.AppliedChannelDisplayName = linkedDisplayName
			if err := p.setChannelBinding(channelID, binding); err != nil {
				_ = p.updateChannelDisplayName(channelID, previousDisplayName)
				p.API.LogWarn("Could not save the reconciled MOED channel binding", "channel_id", channelID, "error", err.Error())
			}
		}
		if len(keys) < pageSize {
			return
		}
	}
}
