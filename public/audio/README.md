# Audio assets for designer
# Place optional sound files in public/audio/ — the game runs silently if absent.

| File | Duration | Description |
|------|----------|-------------|
| spin.mp3 | &lt;1s | Slot machine spin starts (all three drums) |
| reveal.mp3 | &lt;1s | A barrel number is revealed on the slot display |
| match.mp3 | &lt;1s | Revealed number matches a cell on the player's card(s) |
| defeat.mp3 | 1–3s | Player lost when another player wins (`game_over`, `reason: victory`) |
| victory.mp3 | 1–3s | Player received a share of the bank (`received > 0`) |

Format: MP3 (or any format supported by the browser's HTML5 `Audio` element).
Wiring: `public/js/sound.js` preloads and plays via `LottoSound.play(name)`.
Missing files: load/play failures are caught silently; gameplay is unchanged.
