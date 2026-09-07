"""Apply the audited, one-call adapter to the checksum-pinned upstream source."""
from pathlib import Path

path = Path("pkg/message/build.go")
source = path.read_text()
start = source.index("func getMessageHeader(")
end = source.index("\n// SanitizeMessageDate", start)
function = source[start:end]
assert function.count("\treturn hdr\n") == 1, "Upstream header builder changed; review the adapter"
function = function.replace("\treturn hdr\n", "\tsetDNRSenderAuthentication(msg, &hdr)\n\treturn hdr\n")
path.write_text(source[:start] + function + source[end:])
