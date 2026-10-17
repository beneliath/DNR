const SIGNED_MOED_CHANNEL_MARKER = /^\s*\[MOED(?:@([a-z][a-z0-9-]{2,63})#E|#)([1-9][0-9]{0,9})\.[A-Za-z0-9_-]{22}\]\s*/;

export function shortMOEDSidebarChannelDisplayName(displayName) {
    const value = typeof displayName === 'string' ? displayName : '';
    const match = value.match(SIGNED_MOED_CHANNEL_MARKER);
    if (!match || Number(match[2]) > 2147483647) {
        return value;
    }

    const title = value.slice(match[0].length).trimStart();
    return `[MOED#${match[2]}]${title ? ` ${title}` : ''}`;
}
