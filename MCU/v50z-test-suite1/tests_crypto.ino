#include "src/aes-sha/sha256.h"
#include "src/platform/platform.h"

#define CBC 1
#define ECB 0
#define CTR 0
extern "C" {
#include "src/aes-sha/aes.h"
}

#include "src/ra/raConnection.h"


bool log_compare_output( char * label, char * out, char * expected )
{
    logger->log( "out  = %s", out );
    if( strcmp(out,expected)==0 )
    {
        logger->log( "OK", label );
    }
    else
    {
        logger->log( "exp = %s", expected );
        logger->log( "%s ERROR, strings doesn't match!", label );
        return false;
    }

    return true;
}


#define LOG_BUFFER_LENGTH 50
char log_buffer2[LOG_BUFFER_LENGTH+LOG_BUFFER_LENGTH+1];

BYTE hash[SHA256_BLOCK_SIZE];

bool sha256testOne( char * label, unsigned char * in, char * expected )
{
  Sha256*  sha256Instance =new Sha256();
   sha256Instance->init();
  sha256Instance->update(in, strlen((const char *)in));
  sha256Instance->final(hash);
    delete sha256Instance;

  btohexa( hash, SHA256_BLOCK_SIZE, log_buffer2, LOG_BUFFER_LENGTH );
  logger->log( "in = %s", (char *)in );
  return log_compare_output( label, log_buffer2, expected );
}




bool sha256_test()
{
    logger->log( "" );
    logger->log( "Sha-256 test" );

    if( ! sha256testOne( "sha 1 ",  (unsigned char *)"abc", "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad" ) ) return false;
    if( ! sha256testOne( "sha 2 ",  (unsigned char *)"", "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" ) ) return false;
    if( ! sha256testOne( "sha 3 ",  (unsigned char *)"abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq", "248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1" ) ) return false;
    if( ! sha256testOne( "sha 4 ",  (unsigned char *)"abcdefghbcdefghicdefghijdefghijkefghijklfghijklmghijklmnhijklmnoijklmnopjklmnopqklmnopqrlmnopqrsmnopqrstnopqrstu", "cf5b16a778af8380036ce59e7b0492370b249b11e8f07a51afac45037afee9d1" ) ) return false;

    return true;
}




void trng_test()
{
    logger->log( "" );
    logger->log( "trng test" );
    for( int i = 0; i<10; i++ )
    {
        long l = trng();
        logger->log( "rnd = %x", l );
        delay( 100 );
    }
}



bool TestCBC( char * label, char * key , char * initializationVector , char * plainText, char * expectedCipherText, int dlength  )
{
    unsigned char bkey[33];
    unsigned char biv[33];
    unsigned char bpt[65];

    hexatob( key, bkey, 33 );
    hexatob( initializationVector, biv, 33 );
    hexatob( plainText, bpt, 65 );

    struct AES_ctx ctx;

    AES_init_ctx_iv(&ctx, (unsigned char const*)bkey, (unsigned char const*)biv);
    AES_CBC_encrypt_buffer(&ctx, (unsigned char *)bpt, dlength );

    char citext[130];
    btohexa( bpt, dlength, citext, 130 );

    return log_compare_output( label, citext, expectedCipherText );
    
}

bool aes256cbc_test()
{
  logger->log( "" );
  logger->log( "aes test" );
  

  char * key = "603deb1015ca71be2b73aef0857d77811f352c073b6108d72d9810a30914dff4";

  // test of hexatob
  unsigned char bt[65];
  char out[65];
  int ct = hexatob( key, bt, 65 );
  logger->log( "length = %d", (long)ct);
  btohexa( bt, 32, out, 65 );
  log_compare_output( "hexatob ", out, key );
    
// test vectors from https://www.monkeybreadsoftware.net/example-encryption-aes-aestestvectors.shtml
// also can be checked there: https://www.3amsystems.com/Crypto-Toolbox#rijndael-128,cbc,Encrypt

                      //      IV                                  input                               expected output               size
  if( ! TestCBC( "aes 1 ", key, "000102030405060708090A0B0C0D0E0F", "6bc1bee22e409f96e93d7e117393172a", "f58c4c04d6e5f1ba779eabfb5f7bfbd6", 16 ) ) return false;
  if( ! TestCBC( "aes 2 ", key, "F58C4C04D6E5F1BA779EABFB5F7BFBD6", "ae2d8a571e03ac9c9eb76fac45af8e51", "9cfc4e967edb808d679f777bc6702c7d", 16 ) ) return false;
  if( ! TestCBC( "aes 3 ", key, "9CFC4E967EDB808D679F777BC6702C7D", "30c81c46a35ce411e5fbc1191a0a52ef", "39f23369a9d9bacfa530e26304231461", 16 ) ) return false;
  if( ! TestCBC( "aes 4 ", key, "39F23369A9D9BACFA530E26304231461", "f69f2445df4f9b17ad2b417be66c3710", "b2eb05e2c39be9fcda6c19078c6a9d1b", 16 ) ) return false;

  if( ! TestCBC( "aes 5 ", 
            key, 
            "000102030405060708090a0b0c0d0e0f", 
            "6bc1bee22e409f96e93d7e117393172aae2d8a571e03ac9c9eb76fac45af8e5130c81c46a35ce411e5fbc1191a0a52eff69f2445df4f9b17ad2b417be66c3710", 
            "f58c4c04d6e5f1ba779eabfb5f7bfbd69cfc4e967edb808d679f777bc6702c7d39f23369a9d9bacfa530e26304231461b2eb05e2c39be9fcda6c19078c6a9d1b",
            64 ) ) return false;

  return true;
}



bool test_decrypt_cbc(void)
{

    uint8_t key[] = { 0x60, 0x3d, 0xeb, 0x10, 0x15, 0xca, 0x71, 0xbe, 0x2b, 0x73, 0xae, 0xf0, 0x85, 0x7d, 0x77, 0x81,
                      0x1f, 0x35, 0x2c, 0x07, 0x3b, 0x61, 0x08, 0xd7, 0x2d, 0x98, 0x10, 0xa3, 0x09, 0x14, 0xdf, 0xf4 };
    uint8_t in[]  = { 0xf5, 0x8c, 0x4c, 0x04, 0xd6, 0xe5, 0xf1, 0xba, 0x77, 0x9e, 0xab, 0xfb, 0x5f, 0x7b, 0xfb, 0xd6,
                      0x9c, 0xfc, 0x4e, 0x96, 0x7e, 0xdb, 0x80, 0x8d, 0x67, 0x9f, 0x77, 0x7b, 0xc6, 0x70, 0x2c, 0x7d,
                      0x39, 0xf2, 0x33, 0x69, 0xa9, 0xd9, 0xba, 0xcf, 0xa5, 0x30, 0xe2, 0x63, 0x04, 0x23, 0x14, 0x61,
                      0xb2, 0xeb, 0x05, 0xe2, 0xc3, 0x9b, 0xe9, 0xfc, 0xda, 0x6c, 0x19, 0x07, 0x8c, 0x6a, 0x9d, 0x1b };
    uint8_t iv[]  = { 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f };
    uint8_t out[] = { 0x6b, 0xc1, 0xbe, 0xe2, 0x2e, 0x40, 0x9f, 0x96, 0xe9, 0x3d, 0x7e, 0x11, 0x73, 0x93, 0x17, 0x2a,
                      0xae, 0x2d, 0x8a, 0x57, 0x1e, 0x03, 0xac, 0x9c, 0x9e, 0xb7, 0x6f, 0xac, 0x45, 0xaf, 0x8e, 0x51,
                      0x30, 0xc8, 0x1c, 0x46, 0xa3, 0x5c, 0xe4, 0x11, 0xe5, 0xfb, 0xc1, 0x19, 0x1a, 0x0a, 0x52, 0xef,
                      0xf6, 0x9f, 0x24, 0x45, 0xdf, 0x4f, 0x9b, 0x17, 0xad, 0x2b, 0x41, 0x7b, 0xe6, 0x6c, 0x37, 0x10 };

    struct AES_ctx ctx;

    AES_init_ctx_iv(&ctx, key, iv);
    AES_CBC_decrypt_buffer(&ctx, in, 64);

    logger->log("CBC decrypt: ");

    if (0 == memcmp((char*) out, (char*) in, 64))
    {
        logger->log("SUCCESS!");
    }
    else
    {
        logger->log("FAILURE!");
        return false;
    }

    return true;
}

bool test_decrypt_cbc2(void)
{
    unsigned char bkey[33];
    unsigned char biv[33];
    unsigned char bpt[65];

    hexatob( "65dca3d02832e7b57056dbfbc7cf52126fbadb50468ec42f127ceb0e78bf93c3", bkey, 33 );
    hexatob( "efeeb0bf0252cf47577942893305fe7b", biv, 33 );
    hexatob( "00e4624ff82bb77b37f0d00762c30df189a1108a29d08c5dc4d1e2e7fcc089d7c9ac9c4d96fa0dc608c14d2f5b5ed6ab", bpt, 65 );

    struct AES_ctx ctx;

    AES_init_ctx_iv(&ctx, bkey, biv);
    AES_CBC_decrypt_buffer(&ctx, bpt, 64);
    bpt[48] = 0;

    logger->log("CBC decrypt: ");
    logger->log("[%s]", bpt+6 );

    return false;
}



bool do_crypto_tests()
{
  if( ! sha256_test() ) return false;
  trng_test();
  if( ! aes256cbc_test() ) return false;
  if( ! test_decrypt_cbc() ) return false;

  // if( ! test_decrypt_cbc2() ) return false;
  
  return true;
}
