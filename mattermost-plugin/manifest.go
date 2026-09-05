// Package pluginmanifest exposes the bundled manifest as the version authority.
package pluginmanifest

import (
	_ "embed"
	"encoding/json"
)

//go:embed plugin.json
var bundledManifest []byte

func Version() string {
	var manifest struct {
		Version string `json:"version"`
	}
	if err := json.Unmarshal(bundledManifest, &manifest); err != nil || manifest.Version == "" {
		panic("invalid bundled plugin manifest")
	}
	return manifest.Version
}
