# PICK POCKET RADIO — LIVE DEPLOYMENT

## SONO PLAY MINI LIVE × PICK POCKET RADIO

Pick Pocket Radio is the first live radio deployment of SONO PLAY MINI LIVE.

**Berlin — MIND YOUR CULTURE**

Public stream:
`https://radio.pickpocketradio.org/stream.mp3`

## Deployment map

```text
                       SONO PLAY MINI LIVE
                               │
                    SCAN / BPM / ENERGY
                               │
                         PROGRAM / QUEUE
                               │
                           LIQUIDSOAP
                               │
                            ICECAST
                               │
             ┌─────────────────┴─────────────────┐
             │                                   │
      /stream.mp3                         /next-test.mp3
       PUBLIC RADIO                       VALIDATION ENGINE
             │                                   │
    PICK POCKET RADIO                  LIVE CONTROL CONSOLE
                                                 │
                                  ┌──────────────┼──────────────┐
                                  │              │              │
                             LISTENERS        ≫ NEXT        DROP ZONE
                                  │
                              GRAPH / PEAK
                                                 │
                                          RABBIT CONTROL
                                          listeners / next
                                             ≫ NEXT
```

## What is live now

- HTTPS radio domain
- Icecast public stream
- Liquidsoap engine
- SONO programming API
- Stateful test queue
- Test mount `/next-test.mp3`
- Private monitoring/control console
- Public listener count + peak monitoring
- Current / next / queue-position monitoring

## Candidates currently being validated

### Live Console

A private operational console for the radio. It combines:

- Icecast status
- public and test mount status
- public listener count
- listener peak
- listener history graph
- actual/current Liquidsoap information
- next queued track
- queue position
- engine sync status
- `≫ NEXT`
- live audio Drop Zone

### Rabbit Control

A compact controller designed for the Rabbit interface:

- collaboration identity
- listener count + peak
- lightweight listener graph
- current
- next
- large `≫ NEXT`
- no Drop Zone

## Safety / deployment rule

The control interface is tested against `/next-test.mp3` first. The public `/stream.mp3` stays isolated until queue, NEXT, monitoring and drop behavior have been validated.

## Identity

The deployment is presented as:

**SONO PLAY MINI LIVE × PICK POCKET RADIO**

**MIND YOUR CULTURE**
