export const CHANNEL_BINDING_REFRESH_MILLISECONDS = 60000;
export const CHANNEL_BINDING_MAXIMUM_BACKOFF_MILLISECONDS = 10 * 60 * 1000;

export function channelBindingRefreshDelay(consecutiveFailures, randomValue = Math.random()) {
    const failures = Math.max(0, Math.min(10, Number(consecutiveFailures) || 0));
    const boundedRandom = Math.max(0, Math.min(1, Number(randomValue) || 0));
    const exponentialDelay = Math.min(
        CHANNEL_BINDING_REFRESH_MILLISECONDS * (2 ** failures),
        CHANNEL_BINDING_MAXIMUM_BACKOFF_MILLISECONDS,
    );
    const jitteredDelay = Math.round(exponentialDelay * (0.85 + (boundedRandom * 0.3)));
    return Math.min(jitteredDelay, CHANNEL_BINDING_MAXIMUM_BACKOFF_MILLISECONDS);
}
