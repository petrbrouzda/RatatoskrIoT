#ifndef dtostrg_h
#define dtostrg_h

//#define DTOSTR_D


#if defined(ESP8266) || defined(ESP32)
#include "stdlib_noniso.h"
#define MAXPREC 16
#elif defined(_MSC_VER)
#define MAXPREC 16
#else
#define DTOSTRF //Faster on AVR, but slightly slower on ESP8266 
#define	MAXPREC 8
#endif

//#define DTOSTRF 

#define CVTBUFSIZE (MAXPREC+2)

#ifdef __cplusplus
extern "C"{
#endif

#ifndef  DTOSTRF
	static char *cvt(double arg, int ndigits, int *decpt, int *sign, char *buf, int eflag);
	char *fcvtbuf(double arg, int ndigits, int *decpt, int *sign, char *buf);
	char *ecvtbuf(double arg, int ndigits, int *decpt, int *sign, char *buf);  
#endif // DTOSTRF

	char *dtostrg(double value, signed char width, int prec, char *s);
#if defined(ESP8266) || defined(ESP32)
	char *dtostre(double value, char *s, int prec, unsigned char flags);
#endif 
#ifdef DTOSTR_D
#define D_PRINT(msg,a) debug_print(msg,(double) a) 
	void debug_print(char *msg, double d);
#else
#define D_PRINT(msg,a)
#endif //DTOSTR_D

#ifdef __cplusplus
} // extern "C"
#endif


#endif