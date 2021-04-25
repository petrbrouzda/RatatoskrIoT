
/**
 * Popis v Blinker.h
 */

void raBlinkerChangeStateBridge() {
#ifdef USE_BLINKER
  blinker.changeState();
#endif
}

raBlinker::raBlinker( int pin )
{
  this->pin = pin;
}

void raBlinker::changeState()
{
  if( this->code==NULL ) return;

  if( this->position == 0 && 
      ( this->code[0]==-1 || this->code[0]==0 ) ) {
    digitalWrite( this->pin, BLINKER_LED_OFF );
    return;     
  }
  
  if( this->state == LOW ) {
    this->state = HIGH;
  } else {
    this->state = LOW;
  }
  int pos = this->position;
  digitalWrite( this->pin, this->state );
  
  this->position++;
  if( this->code[this->position] == 0 ) {
    this->position = 0;
  }
  if( this->code[this->position] != -1 ) {
    this->ticker.once_ms( this->code[pos], raBlinkerChangeStateBridge );
  }
}

void raBlinker::off()
{
  noInterrupts();
    this->code = NULL;
    this->position = 0;
    this->ticker.detach();
  interrupts();

  this->state = BLINKER_LED_OFF;
  digitalWrite( this->pin, BLINKER_LED_OFF );
}

void raBlinker::setCode( int * code )
{
  noInterrupts();
    this->code = code;
    this->position = 0;
    this->state = BLINKER_LED_OFF;
  interrupts();

  this->changeState();

  /*
  Serial.print( "raBlinker.setCode( " );
  int i = 0;
  while( 1 ) {
    Serial.printf( "%d ", code[i] );
    if( code[i]==0 || code[i]==-1 ) break;
    i++;
  }
  Serial.println( ")" );
  */
}
