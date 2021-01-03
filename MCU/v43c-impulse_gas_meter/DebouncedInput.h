#ifndef X_DEBOUNCEDINPUT_H
#define X_DEBOUNCEDINPUT_H

#include "src/platform/platform.h"

// debounce cas pro zakmitani pri prenuti, msec
#define DEBOUNCE_TIME 100


struct DebouncedInput {
  /** obsluhovany pin */
  const uint8_t pin;
  /** prechod do jakeho stabu (LOW, HIGH) vede k pricteni jednicky? */
  const uint8_t countedState;
  /** interni: aktualni stav */
  volatile int currentState;
  /** interni: kdy doslo k poslednimu impulzu */
  uint32_t debounceTimeStarted;
  /** ziskany pocet */
  volatile long impulseCount;
};

void ESP_ISR_RAM impulse(DebouncedInput * input);

#endif
