// SPDX-License-Identifier: GPL-3.0-or-later
// DNR's narrow adapter for Proton Mail Bridge v3.25.0.
package message

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"strings"

	"github.com/ProtonMail/go-proton-api"
	"github.com/emersion/go-message"
)

const dnrAuthHeader = "X-Dnr-Sender-Authentication"

// Never copy an assertion from ParsedHeaders or a decrypted MIME header. Setting
// the field even on failure also prevents buildRFC822's MIME merge from adding it.
func setDNRSenderAuthentication(msg proton.Message, hdr *message.Header) {
	keyFile := os.Getenv("DNR_INBOUND_ROUTING_KEY_FILE")
	if keyFile == "" {
		return // Unconfigured upstream builds retain their original header layout.
	}
	hdr.Set(dnrAuthHeader, "unavailable")
	encoded, err := os.ReadFile(keyFile)
	if err != nil {
		return
	}
	key, err := base64.StdEncoding.DecodeString(strings.TrimSpace(string(encoded)))
	if err != nil || len(key) != 32 || msg.Sender == nil {
		return
	}
	sender := strings.ToLower(strings.TrimSpace(msg.Sender.Address))
	id := strings.ToLower(strings.Trim(hdr.Get("Message-Id"), "<> \t\r\n"))
	if sender == "" || len(sender) > 254 || id == "" || len(id) > 998 {
		return
	}
	idHash := sha256.Sum256([]byte(id))
	kind := "unverified"
	blocked := proton.MessageFlagImported | proton.MessageFlagDMARCFail |
		proton.MessageFlagSpamAuto | proton.MessageFlagSpamManual |
		proton.MessageFlagPhishingAuto | proton.MessageFlagPhishingManual
	if msg.Flags.Has(proton.MessageFlagReceived) && !msg.Flags.Has(blocked) {
		if msg.Flags.Matches(proton.MessageFlagInternal | proton.MessageFlagE2E) {
			kind = "internal"
		} else if msg.Flags.Has(proton.MessageFlagDMARCPass) {
			kind = "dmarc"
		}
	}
	payload, err := json.Marshal(struct {
		Kind string `json:"kind"`
		From string `json:"from"`
		ID   string `json:"id"`
	}{kind, sender, hex.EncodeToString(idHash[:])})
	if err != nil {
		return
	}
	// Derive a separate protocol key; event routing signatures cannot be used here.
	derive := hmac.New(sha256.New, key)
	derive.Write([]byte("dnr:proton-sender-auth:key:v1"))
	mac := hmac.New(sha256.New, derive.Sum(nil))
	mac.Write([]byte("dnr:proton-sender-auth:v1\n"))
	mac.Write(payload)
	hdr.Set(dnrAuthHeader, "v1."+base64.RawURLEncoding.EncodeToString(payload)+"."+hex.EncodeToString(mac.Sum(nil)))
}
