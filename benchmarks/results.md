# Benchmark Results

Raw output of `benchmarks/run.sh --corpus=corpus --sanitizer=...` on 2026-10-16, on a dedicated
server (Intel Xeon E-2386G, 12 cores, 64 GB) with nothing else running. The corpus was the
3,168 files `tools/fetch-corpus.php` had downloaded from its nineteen sources, plus the 20 HTML
fixtures under `tests/Support/fixtures/`. The sanitizer columns are ezyang/htmlpurifier 4.19.0
installed in a folder outside the repository.

## PHP 8.5

```text
PHP 8.5.11, PCRE 10.44 2024-06-07, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|---------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.003 ms   | 0.010 ms      | 401 MB/s   | 504 KB            | 0.11 ms           | 1.0 MB              |
| plain paragraphs      | 10 KB  | 0.011 ms   | 0.071 ms      | 915 MB/s   | 504 KB            | 0.40 ms           | 1.0 MB              |
| plain paragraphs      | 100 KB | 0.090 ms   | 0.71 ms       | 1083 MB/s  | 504 KB            | 3.25 ms           | 3.4 MB              |
| plain paragraphs      | 1 MB   | 0.96 ms    | 7.06 ms       | 1044 MB/s  | 2.8 MB            | 34.2 ms           | 18.9 MB             |
| a tag every few words | 1 KB   | 0.004 ms   | 0.080 ms      | 319 MB/s   | 444 KB            | 0.42 ms           | 1.1 MB              |
| a tag every few words | 10 KB  | 0.018 ms   | 0.63 ms       | 569 MB/s   | 444 KB            | 2.69 ms           | 3.2 MB              |
| a tag every few words | 100 KB | 0.19 ms    | 6.15 ms       | 511 MB/s   | 444 KB            | 25.8 ms           | 13.0 MB             |
| a tag every few words | 1 MB   | 2.03 ms    | 63.2 ms       | 493 MB/s   | 2.8 MB            | 293.9 ms          | 116.4 MB            |
| Word paste            | 1 KB   | 0.004 ms   | 0.025 ms      | 235 MB/s   | 444 KB            | 0.16 ms           | 1.0 MB              |
| Word paste            | 10 KB  | 0.030 ms   | 0.38 ms       | 358 MB/s   | 0 bytes           | 1.56 ms           | 3.4 MB              |
| Word paste            | 100 KB | 0.25 ms    | 3.28 ms       | 403 MB/s   | 0 bytes           | 13.1 ms           | 6.1 MB              |
| Word paste            | 1 MB   | 2.44 ms    | 33.4 ms       | 410 MB/s   | 2.5 MB            | 143.4 ms          | 38.7 MB             |

## Typical pages

| Page                     | Size  | Tags per KB | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|--------------------------|-------|-------------|------------|---------------|------------|-------------------|-------------------|---------------------|
| news item, 250 words     | 2 KB  | 12          | 0.005 ms   | 0.062 ms      | 445 MB/s   | 444 KB            | 0.34 ms           | 1.0 MB              |
| home page section        | 2 KB  | 20          | 0.005 ms   | 0.088 ms      | 387 MB/s   | 0 bytes           | 0.46 ms           | 1.0 MB              |
| blog post, 1,000 words   | 8 KB  | 10          | 0.012 ms   | 0.18 ms       | 677 MB/s   | 0 bytes           | 0.93 ms           | 3.4 MB              |
| article, 1,800 words     | 15 KB | 10          | 0.020 ms   | 0.32 ms       | 747 MB/s   | 0 bytes           | 1.54 ms           | 3.5 MB              |
| FAQ, 40 questions        | 10 KB | 12          | 0.015 ms   | 0.29 ms       | 676 MB/s   | 0 bytes           | 1.28 ms           | 3.2 MB              |
| policy page, 5,000 words | 36 KB | 5           | 0.037 ms   | 0.39 ms       | 958 MB/s   | 0 bytes           | 1.87 ms           | 3.2 MB              |
| newsletter, 12 blocks    | 9 KB  | 10          | 0.023 ms   | 0.34 ms       | 398 MB/s   | 444 KB            | 1.53 ms           | 3.5 MB              |

## Hostile inputs

| Input                               | Size   | Result                  | Check time | Peak memory added |
|-------------------------------------|--------|-------------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted                | 2.07 ms    | 2.8 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup`       | 1.23 ms    | 504 KB            |
| 10,000 attributes on one tag        | 97 KB  | `attribute-not-allowed` | 7.27 ms    | 2.8 MB            |
| 100,000 levels of nesting           | 1.0 MB | accepted                | 2.31 ms    | 2.0 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted                | 4.57 ms    | 2.8 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted                | 56.0 ms    | 2.0 MB            |
| 1 MB entity name in one value       | 1.0 MB | accepted                | 1.11 ms    | 4.8 MB            |
| 1 MB of short comments              | 1.0 MB | accepted                | 109.8 ms   | 504 KB            |

## Corpus and fixtures: 3,188 files, 6.9 MB, checked in 0.101 s (31,451 files/s, 68 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                    |
|-----------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB      | 2,856 | 0.006 ms        | 0.007 ms      | 0.060 ms (0954.html, 972 bytes) |
| 1 KB to 10 KB   | 229   | 0.036 ms        | 0.054 ms      | 0.28 ms (0025.html, 6 KB)       |
| 10 KB to 100 KB | 97    | 0.19 ms         | 0.23 ms       | 0.72 ms (0138.html, 45 KB)      |
| over 100 KB     | 6     | 7.43 ms         | 7.67 ms       | 15.0 ms (0006.html, 1.1 MB)     |

| Source            | Files | Median per file | Mean per file | Slowest file                                       |
|-------------------|-------|-----------------|---------------|----------------------------------------------------|
| bleach            | 18    | 0.006 ms        | 0.006 ms      | 0.009 ms (0002.html, 50 bytes)                     |
| bluemonday        | 336   | 0.002 ms        | 0.004 ms      | 0.031 ms (0080.html, 348 bytes)                    |
| cerberus          | 3     | 0.40 ms         | 0.38 ms       | 0.52 ms (0002.html, 40 KB)                         |
| ckeditor          | 6     | 0.006 ms        | 0.006 ms      | 0.008 ms (0001.html, 50 bytes)                     |
| ckeditor-paste    | 173   | 0.036 ms        | 0.082 ms      | 0.72 ms (0138.html, 45 KB)                         |
| dompurify         | 208   | 0.008 ms        | 0.010 ms      | 0.060 ms (0013.html, 886 bytes)                    |
| html5lib          | 69    | 0.006 ms        | 0.006 ms      | 0.013 ms (0043.html, 243 bytes)                    |
| html5sec          | 148   | 0.008 ms        | 0.010 ms      | 0.088 ms (0144.html, 1 KB)                         |
| mailchimp         | 44    | 0.19 ms         | 0.19 ms       | 0.34 ms (0001.html, 79 KB)                         |
| mdn               | 102   | 0.012 ms        | 0.017 ms      | 0.16 ms (0086.html, 9 KB)                          |
| owasp             | 95    | 0.005 ms        | 0.005 ms      | 0.015 ms (0009.html, 151 bytes)                    |
| owasp-java        | 286   | 0.006 ms        | 0.006 ms      | 0.030 ms (0271.html, 348 bytes)                    |
| payloads          | 1,047 | 0.006 ms        | 0.006 ms      | 0.060 ms (0954.html, 972 bytes)                    |
| portswigger       | 461   | 0.005 ms        | 0.007 ms      | 0.036 ms (0422.html, 281 bytes)                    |
| tinymce           | 11    | 0.013 ms        | 0.013 ms      | 0.025 ms (0001.html, 145 bytes)                    |
| wikipedia         | 6     | 7.43 ms         | 7.62 ms       | 15.0 ms (0006.html, 1.1 MB)                        |
| wordpress         | 113   | 0.057 ms        | 0.092 ms      | 0.70 ms (0072.html, 29 KB)                         |
| wpt               | 42    | 0.006 ms        | 0.006 ms      | 0.011 ms (0008.html, 55 bytes)                     |
| fixtures/accept   | 8     | 0.015 ms        | 0.028 ms      | 0.071 ms (html5-elements.html, 3 KB)               |
| fixtures/reject   | 10    | 0.010 ms        | 0.009 ms      | 0.014 ms (attribute-not-allowed-1.html, 235 bytes) |
| fixtures/tinymce4 | 2     | 0.14 ms         | 0.14 ms       | 0.16 ms (input.html, 2 KB)                         |

```

## PHP 8.1

```text
PHP 8.1.34, PCRE 10.39 2021-10-29, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|---------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.003 ms   | 0.009 ms      | 414 MB/s   | 504 KB            | 0.10 ms           | 912 KB              |
| plain paragraphs      | 10 KB  | 0.010 ms   | 0.071 ms      | 936 MB/s   | 504 KB            | 0.39 ms           | 912 KB              |
| plain paragraphs      | 100 KB | 0.091 ms   | 0.64 ms       | 1076 MB/s  | 504 KB            | 3.27 ms           | 3.6 MB              |
| plain paragraphs      | 1 MB   | 0.92 ms    | 6.57 ms       | 1090 MB/s  | 2.6 MB            | 34.7 ms           | 22.9 MB             |
| a tag every few words | 1 KB   | 0.004 ms   | 0.077 ms      | 346 MB/s   | 504 KB            | 0.41 ms           | 1.3 MB              |
| a tag every few words | 10 KB  | 0.016 ms   | 0.62 ms       | 611 MB/s   | 504 KB            | 2.69 ms           | 3.4 MB              |
| a tag every few words | 100 KB | 0.20 ms    | 5.88 ms       | 500 MB/s   | 504 KB            | 25.8 ms           | 15.1 MB             |
| a tag every few words | 1 MB   | 2.03 ms    | 60.7 ms       | 493 MB/s   | 2.6 MB            | 300.1 ms          | 130.4 MB            |
| Word paste            | 1 KB   | 0.004 ms   | 0.026 ms      | 247 MB/s   | 504 KB            | 0.16 ms           | 1.3 MB              |
| Word paste            | 10 KB  | 0.029 ms   | 0.37 ms       | 381 MB/s   | 504 KB            | 1.58 ms           | 3.5 MB              |
| Word paste            | 100 KB | 0.23 ms    | 3.29 ms       | 425 MB/s   | 504 KB            | 13.4 ms           | 6.3 MB              |
| Word paste            | 1 MB   | 2.35 ms    | 33.0 ms       | 427 MB/s   | 2.6 MB            | 145.5 ms          | 38.8 MB             |

## Typical pages

| Page                     | Size  | Tags per KB | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|--------------------------|-------|-------------|------------|---------------|------------|-------------------|-------------------|---------------------|
| news item, 250 words     | 2 KB  | 12          | 0.004 ms   | 0.056 ms      | 475 MB/s   | 504 KB            | 0.34 ms           | 1.3 MB              |
| home page section        | 2 KB  | 20          | 0.005 ms   | 0.090 ms      | 407 MB/s   | 504 KB            | 0.45 ms           | 1.3 MB              |
| blog post, 1,000 words   | 8 KB  | 10          | 0.012 ms   | 0.18 ms       | 690 MB/s   | 504 KB            | 0.95 ms           | 3.5 MB              |
| article, 1,800 words     | 15 KB | 10          | 0.019 ms   | 0.32 ms       | 772 MB/s   | 504 KB            | 1.53 ms           | 3.5 MB              |
| FAQ, 40 questions        | 10 KB | 12          | 0.015 ms   | 0.27 ms       | 679 MB/s   | 504 KB            | 1.27 ms           | 3.3 MB              |
| policy page, 5,000 words | 36 KB | 5           | 0.035 ms   | 0.38 ms       | 1006 MB/s  | 504 KB            | 1.91 ms           | 3.3 MB              |
| newsletter, 12 blocks    | 9 KB  | 10          | 0.021 ms   | 0.34 ms       | 427 MB/s   | 504 KB            | 1.60 ms           | 3.6 MB              |

## Hostile inputs

| Input                               | Size   | Result                  | Check time | Peak memory added |
|-------------------------------------|--------|-------------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted                | 2.05 ms    | 2.6 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup`       | 1.20 ms    | 504 KB            |
| 10,000 attributes on one tag        | 97 KB  | `attribute-not-allowed` | 7.43 ms    | 2.6 MB            |
| 100,000 levels of nesting           | 1.0 MB | accepted                | 2.31 ms    | 2.6 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted                | 4.53 ms    | 2.6 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted                | 54.2 ms    | 2.6 MB            |
| 1 MB entity name in one value       | 1.0 MB | accepted                | 1.05 ms    | 4.6 MB            |
| 1 MB of short comments              | 1.0 MB | accepted                | 95.8 ms    | 504 KB            |

## Corpus and fixtures: 3,188 files, 6.9 MB, checked in 0.097 s (32,835 files/s, 71 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                    |
|-----------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB      | 2,856 | 0.005 ms        | 0.006 ms      | 0.057 ms (0709.html, 968 bytes) |
| 1 KB to 10 KB   | 229   | 0.035 ms        | 0.050 ms      | 0.28 ms (0025.html, 6 KB)       |
| 10 KB to 100 KB | 97    | 0.19 ms         | 0.22 ms       | 0.71 ms (0138.html, 45 KB)      |
| over 100 KB     | 6     | 7.23 ms         | 7.38 ms       | 14.3 ms (0006.html, 1.1 MB)     |

| Source            | Files | Median per file | Mean per file | Slowest file                                       |
|-------------------|-------|-----------------|---------------|----------------------------------------------------|
| bleach            | 18    | 0.006 ms        | 0.006 ms      | 0.009 ms (0002.html, 50 bytes)                     |
| bluemonday        | 336   | 0.002 ms        | 0.004 ms      | 0.030 ms (0080.html, 348 bytes)                    |
| cerberus          | 3     | 0.38 ms         | 0.37 ms       | 0.50 ms (0002.html, 40 KB)                         |
| ckeditor          | 6     | 0.005 ms        | 0.006 ms      | 0.007 ms (0001.html, 50 bytes)                     |
| ckeditor-paste    | 173   | 0.035 ms        | 0.080 ms      | 0.71 ms (0138.html, 45 KB)                         |
| dompurify         | 208   | 0.008 ms        | 0.010 ms      | 0.056 ms (0013.html, 886 bytes)                    |
| html5lib          | 69    | 0.006 ms        | 0.006 ms      | 0.013 ms (0043.html, 243 bytes)                    |
| html5sec          | 148   | 0.008 ms        | 0.010 ms      | 0.086 ms (0144.html, 1 KB)                         |
| mailchimp         | 44    | 0.18 ms         | 0.19 ms       | 0.31 ms (0001.html, 79 KB)                         |
| mdn               | 102   | 0.012 ms        | 0.017 ms      | 0.15 ms (0086.html, 9 KB)                          |
| owasp             | 95    | 0.005 ms        | 0.005 ms      | 0.014 ms (0009.html, 151 bytes)                    |
| owasp-java        | 286   | 0.006 ms        | 0.006 ms      | 0.030 ms (0271.html, 348 bytes)                    |
| payloads          | 1,047 | 0.005 ms        | 0.006 ms      | 0.057 ms (0709.html, 968 bytes)                    |
| portswigger       | 461   | 0.005 ms        | 0.007 ms      | 0.035 ms (0422.html, 281 bytes)                    |
| tinymce           | 11    | 0.012 ms        | 0.012 ms      | 0.025 ms (0001.html, 145 bytes)                    |
| wikipedia         | 6     | 7.23 ms         | 7.33 ms       | 14.3 ms (0006.html, 1.1 MB)                        |
| wordpress         | 113   | 0.049 ms        | 0.081 ms      | 0.62 ms (0067.html, 43 KB)                         |
| wpt               | 42    | 0.006 ms        | 0.006 ms      | 0.010 ms (0008.html, 55 bytes)                     |
| fixtures/accept   | 8     | 0.015 ms        | 0.028 ms      | 0.068 ms (html5-elements.html, 3 KB)               |
| fixtures/reject   | 10    | 0.010 ms        | 0.009 ms      | 0.015 ms (attribute-not-allowed-1.html, 235 bytes) |
| fixtures/tinymce4 | 2     | 0.13 ms         | 0.13 ms       | 0.15 ms (input.html, 2 KB)                         |

```
