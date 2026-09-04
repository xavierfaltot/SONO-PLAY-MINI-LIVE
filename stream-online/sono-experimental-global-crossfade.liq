# SONO PLAY MINI LIVE — EXPERIMENTAL GLOBAL CROSSFADE
#
# Historical experiment only. NOT the production configuration.
# A global 8 s crossfade was tested and rejected because SONO must not
# systematically mix every transition. It also caused the online stream
# to stop during the first test.
#
# Keep this file as a reproducible experiment / reference.
# IMPORTANT: never commit the real Icecast source password.

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

# REJECTED BEHAVIOUR: systematic crossfade on every transition.
radio = crossfade(duration=8., radio)

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
