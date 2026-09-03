package main

import (
	"testing"
	"time"
)

func TestChannelBindingResponseCacheExpiresAndInvalidatesByChannel(t *testing.T) {
	now := time.Unix(100, 0)
	cache := channelBindingResponseCache{limit: 4, ttl: time.Minute}
	response := channelBindingResponse{Linked: true, Engagement: apiEngagement{ID: 42}}
	generation := cache.snapshotGeneration()
	cache.setIfGeneration("user-a\x00channel-a", "channel-a", response, now, generation)
	cache.setIfGeneration("user-b\x00channel-a", "channel-a", response, now, generation)
	cache.setIfGeneration("user-a\x00channel-b", "channel-b", response, now, generation)

	if cached, ok := cache.get("user-a\x00channel-a", now.Add(30*time.Second)); !ok || cached.Engagement.ID != 42 {
		t.Fatal("expected a live channel binding response")
	}
	cache.invalidateChannel("channel-a")
	if _, ok := cache.get("user-a\x00channel-a", now.Add(30*time.Second)); ok {
		t.Fatal("expected every response for the changed channel to be invalidated")
	}
	if _, ok := cache.get("user-a\x00channel-b", now.Add(30*time.Second)); !ok {
		t.Fatal("expected another channel to remain cached")
	}
	if _, ok := cache.get("user-a\x00channel-b", now.Add(time.Minute)); ok {
		t.Fatal("expected an expired response to be evicted")
	}
}

func TestChannelBindingResponseCacheRemainsBounded(t *testing.T) {
	now := time.Unix(100, 0)
	cache := channelBindingResponseCache{limit: 2, ttl: time.Minute}
	generation := cache.snapshotGeneration()
	cache.setIfGeneration("first", "channel-a", channelBindingResponse{Linked: true}, now, generation)
	cache.setIfGeneration("second", "channel-b", channelBindingResponse{Linked: true}, now.Add(time.Second), generation)
	cache.setIfGeneration("third", "channel-c", channelBindingResponse{Linked: true}, now.Add(2*time.Second), generation)

	if len(cache.entries) != 2 {
		t.Fatalf("expected two bounded entries, got %d", len(cache.entries))
	}
	if _, ok := cache.get("first", now.Add(3*time.Second)); ok {
		t.Fatal("expected the oldest entry to be evicted at capacity")
	}
}

func TestChannelBindingResponseCacheRejectsInflightResponseAfterInvalidation(t *testing.T) {
	now := time.Unix(100, 0)
	cache := channelBindingResponseCache{limit: 4, ttl: time.Minute}
	staleGeneration := cache.snapshotGeneration()

	cache.invalidateChannel("channel-a")
	stored := cache.setIfGeneration(
		"user-a\x00channel-a",
		"channel-a",
		channelBindingResponse{Linked: true, Engagement: apiEngagement{ID: 42}},
		now,
		staleGeneration,
	)
	if stored {
		t.Fatal("expected a response started before invalidation not to repopulate the cache")
	}
	if _, ok := cache.get("user-a\x00channel-a", now); ok {
		t.Fatal("expected no stale response after invalidation")
	}
}
