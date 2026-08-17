# Audio assets for designer
# Place optional sound files in public/audio/ — the game runs silently if absent.

| File | Duration | Description |
|------|----------|-------------|
| spin.mp3 | loop | Slot machine spin while any drum is spinning — **seamlessly loopable** (no hard silence/gap at the loop point; otherwise looping produces an audible click/stutter) |
| reveal.mp3 | &lt;1s | A barrel number is revealed on the slot display |
| match.mp3 | &lt;1s | Revealed number matches a cell on the player's card(s) |
| defeat.mp3 | 1–3s | Player lost when another player wins (`game_over`, `reason: victory`) |
| victory.mp3 | 1–3s | Player received a share of the bank (`received > 0`) |
| nudge.mp3 | &lt;1s | Another player sent `nudge_turn` (drawer hears `nudge_received`) |
| apartment.mp3 | loop | Tension/countdown cue while a non-immune player must choose pay-or-leave during Apartment — **seamlessly loopable** (same loop-point guidance as spin.mp3) |

Format: MP3 (or any format supported by the browser's HTML5 `Audio` element).
Wiring: `public/js/sound.js` preloads and plays via `LottoSound.play(name)` (one-shot) or `LottoSound.startLoop(name)` / `LottoSound.stopLoop(name)` (looping cues).
Volume: `LottoSound.setVolume(0.0–1.0)` / `getVolume()`; persisted in `localStorage` (`lotto_sound_volume`, 0–100).
Missing files: load/play failures are caught silently; gameplay is unchanged.
