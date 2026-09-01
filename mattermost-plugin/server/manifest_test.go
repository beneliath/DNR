package main

import (
	"encoding/json"
	"os"
	"testing"

	"github.com/mattermost/mattermost/server/public/model"
)

func TestPluginManifestIsValid(t *testing.T) {
	contents, err := os.ReadFile("../plugin.json")
	if err != nil {
		t.Fatalf("read plugin manifest: %v", err)
	}
	var manifest model.Manifest
	if err := json.Unmarshal(contents, &manifest); err != nil {
		t.Fatalf("decode plugin manifest: %v", err)
	}
	if err := manifest.IsValid(); err != nil {
		t.Fatalf("invalid plugin manifest: %v", err)
	}
	if got := manifest.GetExecutableForRuntime("linux", "amd64"); got != "server/dist/plugin-linux-amd64" {
		t.Fatalf("unexpected Linux executable: %s", got)
	}
}
