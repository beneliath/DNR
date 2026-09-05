"""Normalize Git's timezone-aware commit dates for release provenance."""
import datetime
import sys


def timestamp_utc(value):
    timestamp = datetime.datetime.fromisoformat(value)
    if timestamp.utcoffset() is None:
        raise ValueError('Release timestamps must include a timezone')
    return timestamp.astimezone(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


if __name__ == '__main__':
    print(timestamp_utc(sys.argv[1]))
