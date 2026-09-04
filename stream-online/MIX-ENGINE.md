# SONO MIX ENGINE

## Goal

SONO must not mix every track systematically.

Each A → B transition must be decided by SONO before Liquidsoap plays it.

Possible transition modes:

- `CUT` — no overlap.
- `FADE` — short fade when a soft transition is preferable.
- `MIX` — overlap only when the pair genuinely supports it.

## Rules

A mix is allowed only when the transition is musically compatible enough. The decision can use:

- analyzed BPM, never filename BPM;
- energy;
- track role / surprise status;
- available intro/outro material;
- later: structural analysis and beat alignment.

`MIX` duration is adaptive: **2 to 8 seconds maximum**. Eight seconds is a ceiling, never a forced duration.

A `SURPRISE` track should normally favour `CUT`, unless analysis later shows a clearly safe mix.

## Target API shape

The SONO program should eventually expose the transition to the next track explicitly, for example:

```json
{
  "transition": {
    "type": "mix",
    "duration": 6.2
  }
}
```

Liquidsoap should execute SONO's decision rather than applying a global crossfade.

## Status

- Stable no-mix online stream: validated.
- Global `crossfade(duration=8.)`: tested, rejected.
- Adaptive CUT / FADE / MIX engine: design approved, implementation pending.

The stable stream must remain the rollback point while the adaptive engine is developed and tested.
