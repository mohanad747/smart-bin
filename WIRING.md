# Wiring guide (bin unit, firmware 1.3.0)

Picture to follow: Cirkit project **"Smart Bin - Circuit v3"** (Figure 2.4 in the report).
Pins are the ones in report Table 2.2. Build one stage, run its test sketch, and only then start the
next stage. A mistake is easy to find when only one thing is new.

> **Two places where the picture or the text will mislead you. Read these first.**
>
> 1. **Fan on the relay.** The picture draws the fan wire on the terminal marked **常闭** (NC, normally
>    closed). **Do not copy that.** Use **常开** (NO, normally open), as Table 2.2 says. On NC the fan
>    runs all the time, stops when there is gas, and **runs during a fire**.
> 2. **5 V and GND bus.** The text says "one column 5 V and the next GND". In the picture both are in
>    **column 1** of the upper board: holes **A to E = 5 V**, holes **F to J = GND**. The gap in the
>    middle of the board keeps them apart. Follow the holes in this guide.

## How to read the holes

- The **upper board** is the one with the 5 V rail at the top. The **lower board** is below it.
- The numbers run **30 on the left to 1 on the right**. The ESP32 USB socket is on the left.
- `U-F28` = upper board, row F, column 28. `L-E22` = lower board, row E, column 22.
- Rails: `U+` = top red rail (5 V), `U-` = top blue rail (GND), `L+` = bottom red rail (3.3 V),
  `L-` = bottom blue rail (GND). `U+ 30` means the hole next to column 30.
- The two rails in the middle, between the boards, are not used.
- On some boards a rail is cut in the middle. If it is, bridge the two halves with a jumper.
- Holes A to E of a column are joined together. So are F to J. Across the gap they are **not** joined.

## Before power-on (every time)

- [ ] USB and the 18650 are **unplugged** while you move wires.
- [ ] Capacitor: long leg to 5 V (`U+`), leg with the **stripe** to GND (`U-`).
- [ ] No ECHO wire goes straight to an ESP32 pin. Each one goes through its resistors.
- [ ] MQ-2 **AO** goes through its resistors, not straight to GPIO34.
- [ ] Nothing metal touches the 18650 or its holder wires.
- [ ] No wire joins `U+` (5 V) and `L+` (3.3 V).
- [ ] A second person has checked each new wire against this list.

---

## Stage 1: power and rails

ESP32 across the two boards: the **VIN** row in upper row J (VIN in `U-J30`, EN in `U-J16`), the
**3V3** row in lower row A (3V3 in `L-A30`, D23 in `L-A16`).

| ☐ | From | To | Colour |
|---|---|---|---|
| ☐ | `U-F30` (VIN) | `U+ 30` | red |
| ☐ | `U-F29` (GND) | `U- 29` | blue |
| ☐ | `L-E30` (3V3) | `L+ 30` | orange |
| ☐ | `L-E29` (GND) | `L- 29` | blue |
| ☐ | `U+ 1` | `U-A1` (5 V bus, A1 to E1) | red |
| ☐ | `U- 1` | `U-F1` (GND bus, F1 to J1) | blue |
| ☐ | Capacitor long leg (+) | `U+ 7` | |
| ☐ | Capacitor striped leg (-) | `U- 7` | |

**Check**, before plugging anything else in:
- [ ] Power off, multimeter on continuity: no beep between `U+` and `U-`, `L+` and `L-`, `U+` and `L+`.
- [ ] USB in: `U+` to `U-` is about 5 V (4.6 to 5.2), `L+` to `L-` is about 3.3 V.
- [ ] No multimeter? Borrow one for this step if you can. If not: two people check every wire, plug in
      the ESP32 alone, and unplug at once if its light stays off or anything gets warm.
- [ ] `firmware/tests/t1_rails`: the blue LED blinks and Serial says `PASS`.

## Stage 2: fill sensor (HC-SR04 #1)

| ☐ | From | To | Colour |
|---|---|---|---|
| ☐ | HC-SR04 #1 VCC | `U-E1` (5 V bus) | red |
| ☐ | HC-SR04 #1 GND | `L- 17` | blue |
| ☐ | HC-SR04 #1 TRIG | `L-E21` (GPIO19) | green |
| ☐ | HC-SR04 #1 ECHO | `L-J7` | dark blue |
| ☐ | R3 10 kΩ | `L-G7` to `L-G12` | |
| ☐ | R4 first 10 kΩ | `L-H12` to `L-H17` | |
| ☐ | R4 second 10 kΩ | `L-I17` to `L-I22` | |
| ☐ | `L-J22` | `L- 22` | blue |
| ☐ | `L-F12` | `L-E22` (GPIO18) | yellow |

**Check**: `t2_fill`. Hold a book at 10, 20 and 30 cm. Each must read within 1 cm.

## Stage 3: gas, flame, temperature

| ☐ | From | To | Colour |
|---|---|---|---|
| ☐ | MQ-2 VCC | `U+ 5` | red |
| ☐ | MQ-2 GND | `U- 4` | blue |
| ☐ | MQ-2 **AO** (not DO) | `U-A2` | dark red |
| ☐ | R1 10 kΩ | `U-B2` to `U-B7` | |
| ☐ | R2 first 10 kΩ | `U-C7` to `U-C12` | |
| ☐ | R2 second 10 kΩ | `U-D12` to `U-D17` | |
| ☐ | `U-A17` | `U- 17` | blue |
| ☐ | `U-E7` | `U-F19` (GPIO34) | pink |
| ☐ | Flame VCC | `L-C30` (3.3 V) | orange |
| ☐ | Flame GND | `U- 24` | blue |
| ☐ | Flame **DO** (not AO) | `U-F25` (GPIO27) | purple |
| ☐ | DHT22 VCC | `L+ 25` | orange |
| ☐ | DHT22 GND | `L- 24` | blue |
| ☐ | DHT22 DAT | `L-E26` (GPIO4) | navy |

Read the pin names printed on **your** modules. The order is not the same on every module.

**Check**: `t3_sensors`. Warm the DHT22 with your hand, spray air freshener 10 cm from the MQ-2 (after
its first minute), point a TV remote at the flame sensor. Every line must say `PASS`.

## Stage 4: relay, fan and buzzer

| ☐ | From | To | Colour |
|---|---|---|---|
| ☐ | Relay VCC | `L-B30` (3.3 V) | orange |
| ☐ | Relay GND | `U- 21` | blue |
| ☐ | Relay IN | `U-F24` (GPIO26) | green |
| ☐ | Buzzer S | `U-F23` (GPIO25) | purple |
| ☐ | Buzzer middle pin (+) | `L-D30` (3.3 V) | orange |
| ☐ | Buzzer - | `U- 19` | blue |
| ☐ | 18650 + (red) | Relay **COM** (公共端, middle) | red |
| ☐ | Fan + (red) | Relay **NO** (**常开**), **not** 常闭 | red |
| ☐ | Fan - (black) | 18650 - (black) | black |

The 18650 powers only the fan. It shares no wire with the breadboard.

**Check**: `t4_outputs`.
- [ ] Serial says `FAN ON` and the fan **spins**. Serial says `FAN OFF` and it **stops**. The other way
      round means the fan wire is on 常闭 (NC): move it to 常开 (NO).
- [ ] With the USB unplugged the fan does **not** spin. If it does, it is on NC.
- [ ] The buzzer sounds only when Serial says `BUZZER ON`. The other way round: type `flip`, and note
      the level it prints for `BUZZER_ON_LEVEL` in `config.h`.

## Stage 5: lid (HC-SR04 #2 and lid servo)

| ☐ | From | To | Colour |
|---|---|---|---|
| ☐ | HC-SR04 #2 VCC | `U-D1` (5 V bus) | red |
| ☐ | HC-SR04 #2 GND | `L- 1` | blue |
| ☐ | HC-SR04 #2 TRIG | `L-E16` (GPIO23) | teal |
| ☐ | HC-SR04 #2 ECHO | `L-I1` | brown |
| ☐ | R5 10 kΩ | `L-G1` to `L-G6` | |
| ☐ | R6 first 10 kΩ | `L-H6` to `L-H11` | |
| ☐ | R6 second 10 kΩ | `L-I11` to `L-I16` | |
| ☐ | `L-J16` | `L- 16` | blue |
| ☐ | `L-F6` | `L-E17` (GPIO22) | amber |
| ☐ | Lid servo red (+) | `U-C1` (5 V bus) | red |
| ☐ | Lid servo brown (GND) | `U-H1` (GND bus) | blue |
| ☐ | Lid servo orange (signal) | `U-F22` (GPIO33) | purple |

**Check**: `t5_lid`, first with the servo arm **not** joined to the flap. Find the two angles with
`angle <n>`, then fit the arm. Write them down:

- closed angle: ______  open angle: ______  (`LID_CLOSED_DEG`, `LID_OPEN_DEG` in `config.h`)

## Stage 6: fire ball (ball servo)

| ☐ | From | To | Colour |
|---|---|---|---|
| ☐ | Ball servo red (+) | `U-B1` (5 V bus) | red |
| ☐ | Ball servo brown (GND) | `U-G1` (GND bus) | blue |
| ☐ | Ball servo orange (signal) | `U-F28` (GPIO13) | orange |

**Check**: `t6_ball`, first with **no ball** on the arm. Find the two angles, then hang the net and run
`drop` ten times. It must fall every time and never fall on power-up.

- armed angle: ______  release angle: ______  (`BALL_ARMED_DEG`, `BALL_RELEASE_DEG`)
- drops that worked: ____ / 10

---

## Mounting on the split lid

- **Back half: fixed** (glued or screwed). It holds the sensors and the ball.
  - Fill HC-SR04 in the middle, facing straight down.
  - Flame sensor aimed at the middle of the bin.
  - MQ-2 and DHT22 far apart (the MQ-2 heater is hot and would warm the DHT22).
  - Ball and its servo at the back right, out of the fill sensor's view.
- **Front half: a light flap with nothing on it.** Lid servo on the hinge line.
  - Weigh the flap: grams ÷ 1000 × cm from hinge to the middle of the flap must be 0.6 or less.
    If it is more, make the flap from foam board.
- HC-SR04 #2 on the front wall, facing out, with nothing within 30 cm in front of it.
- Activated carbon filter over the fan outlet.
- ESP32 and breadboards outside the bin. Leave slack in the wires to the fixed half.

### Max fill line (`full_pct`)

The waste must never reach the hanging ball, or the ball cannot fall.

1. Empty depth = fill sensor to the bottom of the empty bin: ______ cm (Settings → Bins).
2. Fill sensor down to the **bottom of the hanging ball**: ______ cm.
3. `full_pct` = (empty depth − ball distance − 2) ÷ empty depth × 100 = ______ %.
   Example: 40 cm deep, ball bottom 18 cm below the sensor: (40 − 18 − 2) ÷ 40 = **50 %**.
4. Put it in **Settings → Thresholds → BIN-01 → Full**, and make "Nearly full" lower than it.
5. With the ball hanging and the bin empty, `t2_fill` must still read the full empty depth. If it
   reads less, the sensor sees the ball: move one of them.

---

## Troubleshooting

| Problem | Try |
|---|---|
| ESP32 resets or Wi-Fi drops when a servo moves | Servo current. 1) check the capacitor, 2) try a shorter, thicker USB cable, 3) feed the 5 V bus from the **second USB port** of the power bank: first **remove the wire `U+ 1` to `U-A1`**, then port + to `U-A1` and port - to `U-F1`, so grounds stay joined. Add 470 µF across the bus |
| Servo jitters | Loose signal or GND wire; GND bus not joined to `U-`; weak power bank |
| Servo hums all the time | The flap is too heavy, or the angle pushes against something. Change the angle |
| HC-SR04 always says no echo | VCC not on 5 V; TRIG and ECHO swapped; divider resistor in the wrong column |
| HC-SR04 always reads the same small number | Something in front of it; for #1, the ball or the bin rim |
| MQ-2 reads high for the first minute | Normal, the heater is warming up |
| MQ-2 stuck at 0 | AO wire in the wrong column, or DO used instead of AO |
| Relay clicks but the fan does not spin | 18650 empty; fan + not on 常开 (NO); battery + not on COM |
| Relay does not click | It is a 5 V relay on 3.3 V. Move relay VCC from `L-B30` to `U+`. **Then check** that it still switches OFF: on 5 V some of these modules stay on with a 3.3 V signal. If it stays on, move it back and tell the team |
| Fan spins all the time | Fan + is on 常闭 (NC) |
| Flame sensor always sees a flame | Turn its knob; keep it away from sunlight |
