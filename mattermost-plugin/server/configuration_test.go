package main

import "testing"

func validConfiguration() configuration {
	return configuration{
		MoedURL:               "https://moed.example.test",
		ServiceToken:          "0123456789abcdef0123456789abcdef",
		InstanceID:            "primary",
		RequestTimeoutSeconds: 10,
		EnableChannelLinks:    true,
	}
}

func TestConfigurationValidation(t *testing.T) {
	tests := []struct {
		name   string
		mutate func(*configuration)
		valid  bool
	}{
		{name: "valid", mutate: func(*configuration) {}, valid: true},
		{name: "normalizes trailing slash", mutate: func(c *configuration) { c.MoedURL += "/" }, valid: true},
		{name: "rejects relative URL", mutate: func(c *configuration) { c.MoedURL = "/moed" }},
		{name: "rejects remote HTTP", mutate: func(c *configuration) { c.MoedURL = "http://moed.example.test" }},
		{name: "allows loopback HTTP", mutate: func(c *configuration) { c.MoedURL = "http://127.0.0.1:8080" }, valid: true},
		{name: "rejects URL credentials", mutate: func(c *configuration) { c.MoedURL = "https://user@example.test" }},
		{name: "rejects short token", mutate: func(c *configuration) { c.ServiceToken = "short" }},
		{name: "rejects unsafe instance", mutate: func(c *configuration) { c.InstanceID = "primary/server" }},
		{name: "rejects short timeout", mutate: func(c *configuration) { c.RequestTimeoutSeconds = 1 }},
		{name: "rejects long timeout", mutate: func(c *configuration) { c.RequestTimeoutSeconds = 31 }},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			config := validConfiguration()
			test.mutate(&config)
			err := config.validate()
			if test.valid && err != nil {
				t.Fatalf("expected valid configuration: %v", err)
			}
			if !test.valid && err == nil {
				t.Fatal("expected validation error")
			}
		})
	}
}
