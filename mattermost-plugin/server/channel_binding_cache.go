package main

import (
	"sync"
	"time"
)

const (
	channelBindingCacheLimit = 512
	channelBindingCacheTTL   = 45 * time.Second
)

type cachedChannelBindingResponse struct {
	channelID string
	response  channelBindingResponse
	expiresAt time.Time
}

// channelBindingResponseCache bounds both the lifetime and cardinality of
// user-specific MOED event summaries. The browser checks this endpoint
// periodically, so a small cache prevents each open Mattermost tab from
// repeating the same cross-service lookup.
type channelBindingResponseCache struct {
	mu         sync.Mutex
	entries    map[string]cachedChannelBindingResponse
	generation uint64
	limit      int
	ttl        time.Duration
}

func (cache *channelBindingResponseCache) get(key string, now time.Time) (channelBindingResponse, bool) {
	cache.mu.Lock()
	defer cache.mu.Unlock()
	entry, ok := cache.entries[key]
	if !ok {
		return channelBindingResponse{}, false
	}
	if !now.Before(entry.expiresAt) {
		delete(cache.entries, key)
		return channelBindingResponse{}, false
	}
	return entry.response, true
}

func (cache *channelBindingResponseCache) snapshotGeneration() uint64 {
	cache.mu.Lock()
	defer cache.mu.Unlock()
	return cache.generation
}

func (cache *channelBindingResponseCache) setIfGeneration(
	key string,
	channelID string,
	response channelBindingResponse,
	now time.Time,
	expectedGeneration uint64,
) bool {
	cache.mu.Lock()
	defer cache.mu.Unlock()
	if cache.generation != expectedGeneration {
		return false
	}
	if cache.entries == nil {
		cache.entries = make(map[string]cachedChannelBindingResponse)
	}
	ttl := cache.ttl
	if ttl <= 0 {
		ttl = channelBindingCacheTTL
	}
	limit := cache.limit
	if limit <= 0 {
		limit = channelBindingCacheLimit
	}
	for existingKey, entry := range cache.entries {
		if !now.Before(entry.expiresAt) {
			delete(cache.entries, existingKey)
		}
	}
	if _, exists := cache.entries[key]; !exists && len(cache.entries) >= limit {
		oldestKey := ""
		var oldestExpiry time.Time
		for existingKey, entry := range cache.entries {
			if oldestKey == "" || entry.expiresAt.Before(oldestExpiry) {
				oldestKey = existingKey
				oldestExpiry = entry.expiresAt
			}
		}
		delete(cache.entries, oldestKey)
	}
	cache.entries[key] = cachedChannelBindingResponse{
		channelID: channelID,
		response:  response,
		expiresAt: now.Add(ttl),
	}
	return true
}

func (cache *channelBindingResponseCache) invalidateChannel(channelID string) {
	cache.mu.Lock()
	defer cache.mu.Unlock()
	cache.generation++
	for key, entry := range cache.entries {
		if entry.channelID == channelID {
			delete(cache.entries, key)
		}
	}
}

func (cache *channelBindingResponseCache) clear() {
	cache.mu.Lock()
	defer cache.mu.Unlock()
	cache.generation++
	cache.entries = nil
}
