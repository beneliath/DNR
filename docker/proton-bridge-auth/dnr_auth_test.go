// SPDX-License-Identifier: GPL-3.0-or-later
package message

import (
	"bytes"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/mail"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ProtonMail/go-proton-api"
	"github.com/emersion/go-message"
)

func TestDNRProviderAuthentication(t *testing.T) {
	keyPath := filepath.Join(t.TempDir(), "key")
	if err := os.WriteFile(keyPath, []byte(base64.StdEncoding.EncodeToString([]byte(strings.Repeat("R", 32)))), 0600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("DNR_INBOUND_ROUTING_KEY_FILE", keyPath)
	received := proton.MessageFlagReceived
	internal := received | proton.MessageFlagInternal | proton.MessageFlagE2E
	for _, tc := range []struct {
		name  string
		flags proton.MessageFlag
		want  string
	}{
		{"internal", internal, "internal"},
		{"dmarc", received | proton.MessageFlagDMARCPass, "dmarc"},
		{"external", received, "unverified"},
		{"draft", proton.MessageFlagInternal | proton.MessageFlagE2E, "unverified"},
		{"missing encryption", received | proton.MessageFlagInternal, "unverified"},
		{"imported", internal | proton.MessageFlagImported, "unverified"},
		{"failed dmarc", internal | proton.MessageFlagDMARCFail, "unverified"},
		{"contradictory dmarc", received | proton.MessageFlagDMARCPass | proton.MessageFlagDMARCFail, "unverified"},
		{"spam", internal | proton.MessageFlagSpamAuto, "unverified"},
		{"manual spam", internal | proton.MessageFlagSpamManual, "unverified"},
		{"phishing", internal | proton.MessageFlagPhishingAuto, "unverified"},
		{"manual phishing", internal | proton.MessageFlagPhishingManual, "unverified"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			msg := proton.Message{MessageMetadata: proton.MessageMetadata{
				ID: "provider-id", Flags: tc.flags,
				Sender: &mail.Address{Address: "Staff@Example.Net"},
			}}
			hdr := message.Header{}
			hdr.Set("Message-Id", "<fixture@example.net>")
			hdr.Add(dnrAuthHeader, "sender-forged-1")
			hdr.Add(dnrAuthHeader, "sender-forged-2")
			hdr.Set("X-Pm-Origin", "internal")
			hdr.Set("Authentication-Results", "mail.protonmail.ch; dmarc=pass header.from=example.net")
			setDNRSenderAuthentication(msg, &hdr)
			values := hdr.Values(dnrAuthHeader)
			if len(values) != 1 {
				t.Fatalf("assertions: %v", values)
			}
			parts := strings.Split(values[0], ".")
			if len(parts) != 3 {
				t.Fatalf("invalid assertion: %s", values[0])
			}
			payload, err := base64.RawURLEncoding.DecodeString(parts[1])
			if err != nil {
				t.Fatal(err)
			}
			var data map[string]string
			if err := json.Unmarshal(payload, &data); err != nil {
				t.Fatal(err)
			}
			idHash := sha256.Sum256([]byte("fixture@example.net"))
			if data["kind"] != tc.want || data["from"] != "staff@example.net" || data["id"] != hex.EncodeToString(idHash[:]) {
				t.Fatalf("unexpected provider assertion: %v", data)
			}
			if tc.name == "internal" {
				// Shared with PHP: checks both languages' byte-level protocol.
				const expected = "v1.eyJraW5kIjoiaW50ZXJuYWwiLCJmcm9tIjoic3RhZmZAZXhhbXBsZS5uZXQiLCJpZCI6IjAxNGI2MDg3ZTFiN2M2MWQ1YzJiZDZmNTIzYTFkM2ZkMjI5YmU5ZDI1ZGIxNmE0MWM1Yzg2MmYwMWNjMWU4MzUifQ.11322ab6f9821eb3f25fb2ddcc6a8afb6085d54f7db64cb6930e555f9405a7d9"
				if values[0] != expected {
					t.Fatalf("protocol fixture: %s", values[0])
				}
			}
		})
	}

	t.Setenv("DNR_INBOUND_ROUTING_KEY_FILE", filepath.Join(t.TempDir(), "missing"))
	hdr := message.Header{}
	hdr.Set(dnrAuthHeader, "forged")
	setDNRSenderAuthentication(proton.Message{}, &hdr)
	if hdr.Get(dnrAuthHeader) != "unavailable" {
		t.Fatal("missing key must erase forged assertion")
	}

	// The decrypted MIME merge must not reintroduce an attacker assertion when
	// the adapter has no usable key. This exercises the real upstream merge.
	var output bytes.Buffer
	if err := writeMultipartEncryptedRFC822(hdr, []byte(dnrAuthHeader+": forged-from-body\r\nContent-Type: text/plain\r\n\r\nHello"), &output); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(output.String(), "forged-from-body") || !strings.Contains(output.String(), "unavailable") {
		t.Fatal("MIME merge replaced the adapter assertion")
	}

	// Verify that the actual patched header builder invokes the adapter.
	builtHeader := getMessageHeader(proton.Message{}, JobOptions{})
	if builtHeader.Get(dnrAuthHeader) != "unavailable" {
		t.Fatal("upstream header builder is not wired to the adapter")
	}
}
