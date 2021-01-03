#include "DebouncedInput.h"


// ESP_ISR_RAM = ICACHE_RAM_ATTR resp. IRAM_ATTR podle platformy
void ESP_ISR_RAM impulse(DebouncedInput * input)
{
  long tnow = millis();
  if( (tnow - input->debounceTimeStarted) < DEBOUNCE_TIME ) {
    // nedelame nic, od posledni zmeny uplynulo malo casu
    return;
  }
  int state = digitalRead( input->pin );
  if( state==input->currentState ) {
    // nedelame nic
    return;
  }
  input->debounceTimeStarted = tnow;
  input->currentState = state;
  if( input->currentState==LOW ) {
    input->impulseCount++;
  }
}

void impulseStart( DebouncedInput * input )
{
  input->currentState = digitalRead( input->pin );
}
