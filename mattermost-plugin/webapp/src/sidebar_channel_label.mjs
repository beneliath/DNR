const SIGNED_MOED_CHANNEL_MARKER = /^\s*\[MOED#([0-9]+)\.[A-Za-z0-9_-]{22}\]\s*/;

export function shortMOEDSidebarChannelDisplayName(displayName) {
    const value = typeof displayName === 'string' ? displayName : '';
    const match = value.match(SIGNED_MOED_CHANNEL_MARKER);
    if (!match) {
        return value;
    }

    const title = value.slice(match[0].length).trimStart();
    return `[MOED#${match[1]}]${title ? ` ${title}` : ''}`;
}
