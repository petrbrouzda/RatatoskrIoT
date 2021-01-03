#include "Arduino.h"
#include "dtostrg.h"



#if defined(DTOSTRF)
signed char normalize(double *val) {
	signed char exponent = 0;
	double value = *val;

	while (value >= 10.0) {
		value /= 10.0;
		++exponent;
	}

	while (value < 1.0) {
		value *= 10.0;
		--exponent;
	}
	*val = value;
	return exponent;
}


#if defined(ESP8266)  || defined(ESP32)
char *dtostrgf(double value, signed char width, int prec, char *s, bool flag)
#else
char *dtostrg(double value, signed char width, int prec, char *s)
#endif
{
	//Similar to dtostrf. The output format is either "[-]d.ddd" (%f format) or "[-]d.ddde[-]d" (%e format) depending on width and prec. 
	//The maximum output field is width and the requested number of significant digits printed is prec. 
	//The %f format is prefered if prec can be satisfied else %e format is output with no more than prec significant digits. 	
	//Negative width results in left aligned output
	signed char exponent;
	char reqwidth;
	char ndigits=0,npad;
	bool negative = false;
	bool lalign = false;
	double dtmp;
	char buff[6];
	char *pd;
	
	prec=prec<1?1:prec;
	prec=prec>MAXPREC?MAXPREC:prec;
	
	if(width<0){
		lalign=true;
		width=-width;
	}	
	
	if (isnan(value)) {
		npad=lalign?0:width-3;
		s+=npad;
		strcpy(s, "nan");
		while(npad-- > 0){
			*--s = ' ';
		}
		return s;
	}
	if (isinf(value)) {
		npad=lalign?0:width-3;
		s+=npad;
		strcpy(s, "inf");
		while(npad-- > 0){
			*--s = ' ';
		}
		return s;
	}

	if (value == 0.0) {
		npad=lalign?0:width-1;
		s+=npad;
		strcpy(s, "0");
		while(npad-- > 0){
			*--s = ' ';
		}
		return s;
	}
	
	
	// Handle negative numbers
	if (value < 0.0) {
		negative = true;
		ndigits=1;
	}
	
	dtmp=negative?-value:value;
	exponent=normalize(&dtmp);
#if defined(ESP8266)  || defined(ESP32)
	if(flag){
#endif		
		//Try %f format
		if(exponent>0){
			D_PRINT("debug: exponent: ",exponent);
			D_PRINT("debug: prec: ",prec);
			if(exponent<prec-1){
				ndigits+=prec+1;
				if(ndigits<=width){
					npad=lalign?0:width-ndigits;
					pd=s+npad;
					dtostrf(value,-ndigits,prec-exponent-1,pd);
					while(pd-- > s){
						*pd = ' ';
					}
					return s;
				}
			} else {
				ndigits+=exponent+1;
				if(ndigits<=width){
					npad=lalign?0:width-ndigits;
					pd=s+npad;
					dtostrf(value,-ndigits,0,pd);
					while(pd-- > s){
						*pd = ' ';
					}
					return s;
				}
			}
		} else {
			ndigits+=1-exponent+prec;
			if(ndigits<=width){
				npad=lalign?0:width-ndigits;
				pd=s+npad;
				dtostrf(value,lalign?-ndigits:ndigits,-exponent+prec-1,pd);
				while(pd-- > s){
					*pd = ' ';
				}
				return s;
			}
		} 
#if defined(ESP8266) || defined(ESP32)
	}
#endif		
	//%f format does not fit to width so we need %e format

	*buff='e';
	if(exponent<0){
		exponent=-exponent;
		buff[1]='-';
	} else{
		buff[1]='+';
	}
	if(exponent<10){
		buff[2]='0';
		itoa(exponent, buff+3, 10);
	} else{
		itoa(exponent, buff+2, 10);
	}
	ndigits=strlen(buff);
	reqwidth=prec+ndigits+(prec==1?0:1)+(negative?1:0);
	reqwidth=width<reqwidth?width:reqwidth;
	npad=width-reqwidth;
	pd=lalign?s:s+npad;
	dtostrf(negative?-dtmp:dtmp,reqwidth-ndigits,reqwidth-ndigits-1-(prec==1?0:1)-(negative?1:0),pd);
	while(pd-- > s){
		*pd = ' ';
	}
	strcat(s,buff);
}
#else

signed char normalize(double *val) {
	signed char exponent = 0;
	double value = *val;

	while (value >= 1.0) {
		value /= 10.0;
		++exponent;
	}

	while (value < 0.1) {
		value *= 10.0;
		--exponent;
	}
	*val = value;
	return exponent;
}

#if defined(ESP8266) || defined(ESP32)
char *dtostrgf(double value, signed char width, int prec, char *s, bool flag)
#else
char *dtostrg(double value, signed char width, int prec, char *s)
#endif 
{	
	//Similar to dtostrf. The output format is either "[-]d.ddd" (%f format) or "[-]d.ddde[-]d" (%e format) depending on width and prec. 
	//The maximum output field is width and the requested number of significant digits printed is prec. 
	//The %f format is prefered if prec can be satisfied else %e format is output with no more than prec significant digits. 	
	//Negative width results in left aligned output
	
	signed char exponent;
	int decpt,cvtsign;
	char sign=0;
	char npad;
	char reqwdt;
	//	bool negative = false;
	bool lalign = false;
	char *pd,*ps;
	signed char i;
	char buff[6];

	
	if(width<0){
		lalign=true;
		width=-width;
	}	
	
	prec=prec>width?width:prec;
	prec=prec<1?1:prec;
	prec=prec>MAXPREC?MAXPREC:prec;
	
	if (isnan(value)) {
		npad=lalign?0:width-3;
		s+=npad;
		strcpy(s, "nan");
		while(npad-- > 0)*--s = ' ';
		return s;
	}
	
	if (isinf(value)) {
		npad=lalign?0:width-3;
		s+=npad;
		strcpy(s, "inf");
		while(npad-- > 0)*--s = ' ';
		return s;
	}

	if (value == 0.0) {
		npad=lalign?0:width-1;
		s+=npad;
		strcpy(s, "0");
		while(npad-- > 0)*--s = ' ';
		return s;
	}
	
	// Handle negative numbers
	if (value < 0.0) {
		sign = 1;
		value=-value;
	}
	exponent=normalize(&value);
	D_PRINT("debug: normalized: ",value);
	D_PRINT("debug: exponent: ",exponent);
#if defined(ESP8266) || defined(ESP32)
	if(flag){
#endif	
		if(exponent<1){
			reqwdt=prec+2-exponent+sign;
			if(reqwdt<=width){
				ecvtbuf(value, prec, &decpt,&cvtsign,s);
				npad=lalign?0:width-reqwdt;
				pd=s+reqwdt+npad;
				*pd--=0;
				ps=s+prec-1;
				while(ps>=s)*pd--=*ps--;
				while(exponent++<0) *pd--='0';
				*pd--='.';
				*pd--='0';
				if(sign!=0)*pd--='-';
				while(pd>=s) *pd--=' ';
				return s;
			}			
		} else{
			reqwdt=(prec>exponent?prec+1:exponent)+sign;
			if(reqwdt<=width){
				D_PRINT("debug: reqwdt: ",reqwdt);
				D_PRINT("debug: prec: ",prec);
				ecvtbuf(value, prec, &decpt,&cvtsign,s);
				npad=lalign?0:width-reqwdt;
				ps=s+prec-1;
				pd=s+reqwdt+npad;
				*pd--=0;
				i=prec-exponent>0?prec-exponent:prec-exponent;
				if(i>0){
					while(i-->0)*pd--=*ps--;
					*pd--='.';
				} else {
					while(i++ < 0) *pd--='0';
				}
				while(ps>=s) *pd--=*ps--;
				if(sign!=0) *pd--='-';
				while(pd>=s) *pd--=' ';
				return s;	
			}
		}
#if defined(ESP8266) || defined(ESP32)
	}
#endif	

	//%f format does not fit to width so we need %e format
	*buff='e';
	exponent--;
	if(exponent<0){
		exponent=-exponent;
		buff[1]='-';
	} else{
		buff[1]='+';
	}
	if(exponent<10){
		buff[2]='0';
		itoa(exponent, buff+3, 10);
	} else{
		itoa(exponent, buff+2, 10);
	}
	exponent=strlen(buff);
	reqwdt=(prec>1?prec+1:prec)+exponent+sign;
	if(width<reqwdt){
		prec=width-exponent-1-sign;
		reqwdt=width;
	}
	ecvtbuf(value, prec, &decpt,&cvtsign,s);
	npad=lalign?0:width-reqwdt;
	pd=s+reqwdt+npad+1;
	*pd--=0;
	ps=buff+exponent;
	while(ps >= buff) *pd-- = *ps--;
	ps=s+prec-1;
	while(ps > s) *pd-- = *ps--;
	if(prec>1)*pd-- ='.';
	while(ps >= s) *pd-- = *ps--;
	if(sign) *pd-- = '-';
	while(pd >= s) *pd-- = ' ';
	return s;
}	

#endif

#if defined(ESP8266) || defined(ESP32)
char *dtostrg(double value, signed char width, int prec, char *s){
	return dtostrgf(value, width, prec, s, true);
}

char *dtostre(double value, char *s, int prec, unsigned char flags){
	return dtostrgf(value, -((signed char)prec+8), prec+1, s, false);
}
#endif
