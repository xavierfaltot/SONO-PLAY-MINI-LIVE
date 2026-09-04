# SONO PLAY MINI LIVE — STABLE / NO MIX
# Validated online stream configuration.
#
# IMPORTANT: never commit the real Icecast source password.
# Replace CHANGE_ME_SOURCE_PASSWORD only on the VPS.

API = "https://toutvabiensepasser.com/SONO-PLAY-MINI-LIVE/index.php?action=now"

def sono_request() =
  url = process.read("curl -fsS '#{API}' | jq -r '.current.url'")
  position = process.read("curl -fsS '#{API}' | jq -r '.current.position'")

  url = string.trim(url)
  position = string.trim(position)

  log("SONO URL → #{url}")
  log("SONO POSITION → #{position}s")

  request.create(
    "annotate:liq_cue_in=\"#{position}\":#{url}"
  )
end

radio = request.dynamic(
  prefetch=1,
  sono_request
)

radio = fallback(track_sensitive=false, [
  radio,
  blank()
])

output.icecast(
  %mp3(bitrate=128),
  host="127.0.0.1",
  port=8000,
  password="CHANGE_ME_SOURCE_PASSWORD",
  mount="/stream.mp3",
  name="SONO PLAY MINI LIVE",
  description="SONO PLAY MINI LIVE",
  radio
)
