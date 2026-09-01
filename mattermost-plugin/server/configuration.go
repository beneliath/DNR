package main

import (
	"fmt"
	"net/url"
	"regexp"
	"strings"
	"time"
)

var instanceIDPattern = regexp.MustCompile(`^[A-Za-z0-9._-]{1,100}$`)

type configuration struct {
	MoedURL               string
	ServiceToken          string
	InstanceID            string
	RequestTimeoutSeconds int
	EnableChannelLinks    bool
}

func (c *configuration) validate() error {
	c.MoedURL = strings.TrimRight(strings.TrimSpace(c.MoedURL), "/")
	c.ServiceToken = strings.TrimSpace(c.ServiceToken)
	c.InstanceID = strings.TrimSpace(c.InstanceID)
	parsed, err := url.Parse(c.MoedURL)
	if err != nil || parsed.Host == "" || (parsed.Scheme != "http" && parsed.Scheme != "https") {
		return fmt.Errorf("Moed URL must be an absolute HTTP(S) URL")
	}
	if parsed.User != nil || parsed.RawQuery != "" || parsed.Fragment != "" {
		return fmt.Errorf("Moed URL cannot contain credentials, a query, or a fragment")
	}
	if parsed.Scheme == "http" && parsed.Hostname() != "localhost" && parsed.Hostname() != "127.0.0.1" && parsed.Hostname() != "::1" {
		return fmt.Errorf("Moed URL must use HTTPS except for loopback development")
	}
	if len(c.ServiceToken) < 32 {
		return fmt.Errorf("Service Token must contain at least 32 characters")
	}
	if !instanceIDPattern.MatchString(c.InstanceID) {
		return fmt.Errorf("Instance ID may contain letters, numbers, dots, underscores, and hyphens")
	}
	if c.RequestTimeoutSeconds < 2 || c.RequestTimeoutSeconds > 30 {
		return fmt.Errorf("Request Timeout must be between 2 and 30 seconds")
	}
	return nil
}

func (c *configuration) timeout() time.Duration {
	return time.Duration(c.RequestTimeoutSeconds) * time.Second
}
