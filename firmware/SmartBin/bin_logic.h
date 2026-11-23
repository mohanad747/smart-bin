// =====================================================================
// Smart Bin - the decisions for the lid and the fire ball.
// No Arduino calls in here, only "time and facts in, decision out", so the
// rules can be read and tested without the hardware.
// =====================================================================
#pragma once
#include <stdint.h>
#include "config.h"

enum LidState { LID_CLOSED, LID_OPEN_HAND, LID_OPEN_SERVICE, LID_LOCKED_FULL, LID_LOCKED_FIRE };
static const char* const LID_NAME[] = { "CLOSED", "OPEN_HAND", "OPEN_SERVICE", "LOCKED_FULL", "LOCKED_FIRE" };

// ---------------------------------------------------------------------
// Lid: checked every loop, most important rule first
// ---------------------------------------------------------------------
struct LidLogic {
  LidState state   = LID_CLOSED;
  uint32_t opens   = 0;          // how many times it opened since boot
  bool     beepNow = false;      // true for one pass: give the double "full" beep

  bool isOpen() const { return state == LID_OPEN_HAND || state == LID_OPEN_SERVICE; }

  // Dashboard / Serial "open for emptying". Refused during a fire.
  bool openService(uint32_t now, bool fire) {
    if (fire) return false;
    service = true;
    serviceMs = now;
    return true;
  }
  void closeService() { service = false; }

  // full = waste at the limit, online = server reached in the last 5 min
  void update(uint32_t now, bool fire, bool full, bool online, bool hand) {
    beepNow = false;
    if (hand) { lastHandMs = now; handEver = true; }
    if (fire) service = false;                                     // a fire cancels the emptying
    if (service && now - serviceMs >= LID_SERVICE_MAX_MS) service = false;

    LidState next;
    if (fire)                      next = LID_LOCKED_FIRE;         // 1. nothing opens it, not even the dashboard
    else if (service)              next = LID_OPEN_SERVICE;        // 2. allowed when full: that is the point
    else if (full && online)       next = LID_LOCKED_FULL;         // 3. offline 5 min: people can still use the bin
    else if (hand)                 next = LID_OPEN_HAND;           // 4.
    else if (state == LID_OPEN_HAND && now - lastHandMs < LID_CLOSE_DELAY_MS) next = LID_OPEN_HAND;
    else                           next = LID_CLOSED;              // 5.

    if (next == LID_LOCKED_FULL && hand && (!beeped || now - lastBeepMs >= FULL_BEEP_GAP_MS)) {
      beepNow = true;
      beeped = true;
      lastBeepMs = now;
    }
    bool wasOpen = isOpen();
    state = next;
    if (isOpen() && !wasOpen) opens++;
  }

private:
  bool     service = false, handEver = false, beeped = false;
  uint32_t serviceMs = 0, lastHandMs = 0, lastBeepMs = 0;
};

// ---------------------------------------------------------------------
// Full or not: 3 measurements in a row at the limit = full. It is not full
// any more when the fill is FULL_UNLOCK_PCT under the limit, 3 in a row.
// ---------------------------------------------------------------------
struct FullLogic {
  bool full = false;
  void add(float fillPct, int fullPct) {
    bool other = full ? fillPct < fullPct - FULL_UNLOCK_PCT : fillPct >= fullPct;
    count = other ? count + 1 : 0;
    if (count >= 3) { full = !full; count = 0; }
  }
private:
  int count = 0;
};

// ---------------------------------------------------------------------
// Flame seen for BALL_FLAME_MS (short gaps allowed, a flame flickers).
// One wrong reading of the sensor never drops the ball.
// ---------------------------------------------------------------------
struct FlameRun {
  // call often; returns true while the flame is confirmed
  bool update(uint32_t now, bool seen) {
    if (seen) {
      if (!on || now - lastMs > BALL_FLAME_GAP_MS) sinceMs = now;
      on = true;
      lastMs = now;
    } else if (on && now - lastMs > BALL_FLAME_GAP_MS) {
      on = false;
    }
    return seen && now - sinceMs >= BALL_FLAME_MS;   // and it must still be there now
  }
  void reset() { on = false; }
private:
  bool     on = false;
  uint32_t sinceMs = 0, lastMs = 0;
};

// ---------------------------------------------------------------------
// Ball release: turn to release, wait, turn back. One drop per arming.
// ---------------------------------------------------------------------
struct BallLogic {
  bool armed = true;
  bool atRelease = false;        // where the servo must be

  bool moving() const { return step != IDLE; }            // the lid waits for this (not for "wanted")
  bool busy()   const { return step != IDLE || wanted; }

  // ask for a drop; nothing happens if the ball was already dropped
  void drop() { if (armed && step == IDLE) wanted = true; }
  bool arm()  { if (busy()) return false; armed = true; return true; }

  // returns true once, at the moment the ball counts as released
  bool update(uint32_t now, bool lidMoving) {
    switch (step) {
      case IDLE:
        if (wanted && !lidMoving) {        // never both servos at once
          wanted = false;
          atRelease = true;
          step = RELEASE;
          stepMs = now;
        }
        break;
      case RELEASE:
        if (now - stepMs >= BALL_HOLD_MS) {
          atRelease = false;
          step = BACK;
          stepMs = now;
          armed = false;
          return true;
        }
        break;
      case BACK:
        if (now - stepMs >= BALL_BACK_MS) step = IDLE;
        break;
    }
    return false;
  }

private:
  enum { IDLE, RELEASE, BACK } step = IDLE;
  bool     wanted = false;
  uint32_t stepMs = 0;
};
