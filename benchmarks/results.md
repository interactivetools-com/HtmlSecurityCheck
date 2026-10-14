# Benchmark Results

Raw output of `benchmarks/run.sh --corpus=corpus --sanitizer=...` on 2026-10-13, on a dedicated
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
| plain paragraphs      | 1 KB   | 0.003 ms   | 0.010 ms      | 403 MB/s   | 504 KB            | 0.11 ms           | 708 KB              |
| plain paragraphs      | 10 KB  | 0.011 ms   | 0.075 ms      | 925 MB/s   | 504 KB            | 0.42 ms           | 1.0 MB              |
| plain paragraphs      | 100 KB | 0.092 ms   | 0.68 ms       | 1058 MB/s  | 504 KB            | 3.24 ms           | 3.3 MB              |
| plain paragraphs      | 1 MB   | 0.94 ms    | 6.97 ms       | 1066 MB/s  | 2.8 MB            | 33.5 ms           | 18.9 MB             |
| a tag every few words | 1 KB   | 0.004 ms   | 0.076 ms      | 343 MB/s   | 504 KB            | 0.41 ms           | 1.0 MB              |
| a tag every few words | 10 KB  | 0.018 ms   | 0.61 ms       | 572 MB/s   | 504 KB            | 2.69 ms           | 3.2 MB              |
| a tag every few words | 100 KB | 0.19 ms    | 5.97 ms       | 506 MB/s   | 504 KB            | 25.6 ms           | 13.0 MB             |
| a tag every few words | 1 MB   | 2.05 ms    | 61.1 ms       | 489 MB/s   | 2.8 MB            | 296.7 ms          | 116.3 MB            |
| Word paste            | 1 KB   | 0.004 ms   | 0.023 ms      | 242 MB/s   | 504 KB            | 0.16 ms           | 1.0 MB              |
| Word paste            | 10 KB  | 0.029 ms   | 0.33 ms       | 370 MB/s   | 444 KB            | 1.57 ms           | 3.3 MB              |
| Word paste            | 100 KB | 0.24 ms    | 3.03 ms       | 404 MB/s   | 444 KB            | 13.0 ms           | 6.3 MB              |
| Word paste            | 1 MB   | 2.53 ms    | 30.5 ms       | 396 MB/s   | 2.8 MB            | 140.2 ms          | 38.8 MB             |

## Typical pages

| Page                     | Size  | Tags per KB | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|--------------------------|-------|-------------|------------|---------------|------------|-------------------|-------------------|---------------------|
| news item, 250 words     | 2 KB  | 12          | 0.004 ms   | 0.057 ms      | 466 MB/s   | 444 KB            | 0.34 ms           | 1.1 MB              |
| home page section        | 2 KB  | 20          | 0.005 ms   | 0.085 ms      | 384 MB/s   | 444 KB            | 0.45 ms           | 1.1 MB              |
| blog post, 1,000 words   | 8 KB  | 10          | 0.012 ms   | 0.18 ms       | 680 MB/s   | 444 KB            | 0.94 ms           | 3.4 MB              |
| article, 1,800 words     | 15 KB | 10          | 0.020 ms   | 0.31 ms       | 737 MB/s   | 444 KB            | 1.55 ms           | 3.4 MB              |
| FAQ, 40 questions        | 10 KB | 12          | 0.015 ms   | 0.28 ms       | 687 MB/s   | 444 KB            | 1.26 ms           | 3.1 MB              |
| policy page, 5,000 words | 36 KB | 5           | 0.038 ms   | 0.38 ms       | 921 MB/s   | 504 KB            | 1.88 ms           | 3.1 MB              |
| newsletter, 12 blocks    | 9 KB  | 10          | 0.023 ms   | 0.32 ms       | 398 MB/s   | 504 KB            | 1.58 ms           | 3.5 MB              |

## Hostile inputs

| Input                               | Size   | Result                  | Check time | Peak memory added |
|-------------------------------------|--------|-------------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted                | 2.09 ms    | 2.8 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup`       | 1.22 ms    | 504 KB            |
| 10,000 attributes on one tag        | 97 KB  | `attribute-not-allowed` | 7.21 ms    | 2.8 MB            |
| 100,000 levels of nesting           | 1.0 MB | accepted                | 2.43 ms    | 2.0 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted                | 3.69 ms    | 2.8 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted                | 55.5 ms    | 2.0 MB            |
| 1 MB entity name in one value       | 1.0 MB | accepted                | 1.06 ms    | 4.8 MB            |
| 1 MB of short comments              | 1.0 MB | accepted                | 109.8 ms   | 504 KB            |

## Corpus and fixtures: 3,188 files, 6.9 MB, checked in 0.096 s (33,275 files/s, 72 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                    |
|-----------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB      | 2,856 | 0.005 ms        | 0.006 ms      | 0.059 ms (0954.html, 972 bytes) |
| 1 KB to 10 KB   | 229   | 0.033 ms        | 0.051 ms      | 0.27 ms (0025.html, 6 KB)       |
| 10 KB to 100 KB | 97    | 0.16 ms         | 0.21 ms       | 0.71 ms (0072.html, 29 KB)      |
| over 100 KB     | 6     | 7.25 ms         | 7.48 ms       | 14.7 ms (0006.html, 1.1 MB)     |

| Source            | Files | Median per file | Mean per file | Slowest file                                       |
|-------------------|-------|-----------------|---------------|----------------------------------------------------|
| bleach            | 18    | 0.006 ms        | 0.006 ms      | 0.008 ms (0002.html, 50 bytes)                     |
| bluemonday        | 336   | 0.002 ms        | 0.004 ms      | 0.030 ms (0080.html, 348 bytes)                    |
| cerberus          | 3     | 0.38 ms         | 0.36 ms       | 0.49 ms (0002.html, 40 KB)                         |
| ckeditor          | 6     | 0.005 ms        | 0.006 ms      | 0.007 ms (0001.html, 50 bytes)                     |
| ckeditor-paste    | 173   | 0.031 ms        | 0.074 ms      | 0.67 ms (0138.html, 45 KB)                         |
| dompurify         | 208   | 0.008 ms        | 0.009 ms      | 0.057 ms (0013.html, 886 bytes)                    |
| html5lib          | 69    | 0.006 ms        | 0.006 ms      | 0.013 ms (0042.html, 140 bytes)                    |
| html5sec          | 148   | 0.007 ms        | 0.010 ms      | 0.084 ms (0144.html, 1 KB)                         |
| mailchimp         | 44    | 0.15 ms         | 0.15 ms       | 0.34 ms (0001.html, 79 KB)                         |
| mdn               | 102   | 0.012 ms        | 0.017 ms      | 0.15 ms (0086.html, 9 KB)                          |
| owasp             | 95    | 0.005 ms        | 0.005 ms      | 0.014 ms (0008.html, 229 bytes)                    |
| owasp-java        | 286   | 0.006 ms        | 0.006 ms      | 0.030 ms (0271.html, 348 bytes)                    |
| payloads          | 1,047 | 0.005 ms        | 0.006 ms      | 0.059 ms (0954.html, 972 bytes)                    |
| portswigger       | 461   | 0.005 ms        | 0.007 ms      | 0.034 ms (0422.html, 281 bytes)                    |
| tinymce           | 11    | 0.013 ms        | 0.012 ms      | 0.024 ms (0001.html, 145 bytes)                    |
| wikipedia         | 6     | 7.25 ms         | 7.44 ms       | 14.7 ms (0006.html, 1.1 MB)                        |
| wordpress         | 113   | 0.056 ms        | 0.090 ms      | 0.71 ms (0072.html, 29 KB)                         |
| wpt               | 42    | 0.006 ms        | 0.006 ms      | 0.010 ms (0008.html, 55 bytes)                     |
| fixtures/accept   | 8     | 0.013 ms        | 0.026 ms      | 0.068 ms (html5-elements.html, 3 KB)               |
| fixtures/reject   | 10    | 0.009 ms        | 0.009 ms      | 0.014 ms (attribute-not-allowed-1.html, 235 bytes) |
| fixtures/tinymce4 | 2     | 0.13 ms         | 0.13 ms       | 0.15 ms (input.html, 2 KB)                         |

```

## PHP 8.1

```text
PHP 8.1.34, PCRE 10.39 2021-10-29, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|---------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.003 ms   | 0.009 ms      | 469 MB/s   | 504 KB            | 0.11 ms           | 912 KB              |
| plain paragraphs      | 10 KB  | 0.010 ms   | 0.072 ms      | 969 MB/s   | 504 KB            | 0.40 ms           | 912 KB              |
| plain paragraphs      | 100 KB | 0.088 ms   | 0.66 ms       | 1108 MB/s  | 504 KB            | 3.27 ms           | 3.5 MB              |
| plain paragraphs      | 1 MB   | 0.95 ms    | 6.62 ms       | 1055 MB/s  | 2.6 MB            | 34.5 ms           | 22.8 MB             |
| a tag every few words | 1 KB   | 0.003 ms   | 0.074 ms      | 358 MB/s   | 504 KB            | 0.42 ms           | 1.3 MB              |
| a tag every few words | 10 KB  | 0.017 ms   | 0.60 ms       | 579 MB/s   | 504 KB            | 2.68 ms           | 3.3 MB              |
| a tag every few words | 100 KB | 0.19 ms    | 5.71 ms       | 507 MB/s   | 504 KB            | 26.2 ms           | 15.2 MB             |
| a tag every few words | 1 MB   | 2.02 ms    | 58.6 ms       | 495 MB/s   | 2.6 MB            | 298.3 ms          | 130.3 MB            |
| Word paste            | 1 KB   | 0.004 ms   | 0.023 ms      | 251 MB/s   | 504 KB            | 0.16 ms           | 1.3 MB              |
| Word paste            | 10 KB  | 0.029 ms   | 0.33 ms       | 371 MB/s   | 504 KB            | 1.62 ms           | 3.5 MB              |
| Word paste            | 100 KB | 0.24 ms    | 3.02 ms       | 406 MB/s   | 504 KB            | 13.4 ms           | 6.3 MB              |
| Word paste            | 1 MB   | 2.48 ms    | 30.2 ms       | 403 MB/s   | 2.6 MB            | 146.0 ms          | 38.7 MB             |

## Typical pages

| Page                     | Size  | Tags per KB | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|--------------------------|-------|-------------|------------|---------------|------------|-------------------|-------------------|---------------------|
| news item, 250 words     | 2 KB  | 12          | 0.004 ms   | 0.054 ms      | 495 MB/s   | 504 KB            | 0.34 ms           | 1.3 MB              |
| home page section        | 2 KB  | 20          | 0.004 ms   | 0.085 ms      | 422 MB/s   | 504 KB            | 0.46 ms           | 1.3 MB              |
| blog post, 1,000 words   | 8 KB  | 10          | 0.012 ms   | 0.18 ms       | 700 MB/s   | 504 KB            | 0.94 ms           | 3.5 MB              |
| article, 1,800 words     | 15 KB | 10          | 0.020 ms   | 0.31 ms       | 759 MB/s   | 504 KB            | 1.53 ms           | 3.5 MB              |
| FAQ, 40 questions        | 10 KB | 12          | 0.014 ms   | 0.27 ms       | 711 MB/s   | 504 KB            | 1.26 ms           | 3.3 MB              |
| policy page, 5,000 words | 36 KB | 5           | 0.035 ms   | 0.37 ms       | 999 MB/s   | 504 KB            | 1.83 ms           | 3.3 MB              |
| newsletter, 12 blocks    | 9 KB  | 10          | 0.024 ms   | 0.31 ms       | 385 MB/s   | 504 KB            | 1.54 ms           | 3.7 MB              |

## Hostile inputs

| Input                               | Size   | Result                  | Check time | Peak memory added |
|-------------------------------------|--------|-------------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted                | 2.04 ms    | 2.6 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup`       | 1.20 ms    | 504 KB            |
| 10,000 attributes on one tag        | 97 KB  | `attribute-not-allowed` | 7.32 ms    | 2.6 MB            |
| 100,000 levels of nesting           | 1.0 MB | accepted                | 2.36 ms    | 2.6 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted                | 3.62 ms    | 2.6 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted                | 54.0 ms    | 2.6 MB            |
| 1 MB entity name in one value       | 1.0 MB | accepted                | 1.07 ms    | 4.6 MB            |
| 1 MB of short comments              | 1.0 MB | accepted                | 106.6 ms   | 504 KB            |

## Corpus and fixtures: 3,188 files, 6.9 MB, checked in 0.092 s (34,745 files/s, 75 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                    |
|-----------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB      | 2,856 | 0.005 ms        | 0.006 ms      | 0.057 ms (0709.html, 968 bytes) |
| 1 KB to 10 KB   | 229   | 0.032 ms        | 0.048 ms      | 0.26 ms (0025.html, 6 KB)       |
| 10 KB to 100 KB | 97    | 0.15 ms         | 0.20 ms       | 0.65 ms (0072.html, 29 KB)      |
| over 100 KB     | 6     | 6.95 ms         | 7.16 ms       | 14.1 ms (0006.html, 1.1 MB)     |

| Source            | Files | Median per file | Mean per file | Slowest file                                       |
|-------------------|-------|-----------------|---------------|----------------------------------------------------|
| bleach            | 18    | 0.005 ms        | 0.005 ms      | 0.008 ms (0006.html, 42 bytes)                     |
| bluemonday        | 336   | 0.002 ms        | 0.004 ms      | 0.030 ms (0080.html, 348 bytes)                    |
| cerberus          | 3     | 0.36 ms         | 0.35 ms       | 0.47 ms (0002.html, 40 KB)                         |
| ckeditor          | 6     | 0.005 ms        | 0.005 ms      | 0.007 ms (0001.html, 50 bytes)                     |
| ckeditor-paste    | 173   | 0.030 ms        | 0.071 ms      | 0.65 ms (0139.html, 34 KB)                         |
| dompurify         | 208   | 0.007 ms        | 0.009 ms      | 0.054 ms (0013.html, 886 bytes)                    |
| html5lib          | 69    | 0.005 ms        | 0.006 ms      | 0.012 ms (0043.html, 243 bytes)                    |
| html5sec          | 148   | 0.007 ms        | 0.010 ms      | 0.081 ms (0144.html, 1 KB)                         |
| mailchimp         | 44    | 0.14 ms         | 0.15 ms       | 0.32 ms (0001.html, 79 KB)                         |
| mdn               | 102   | 0.012 ms        | 0.016 ms      | 0.14 ms (0086.html, 9 KB)                          |
| owasp             | 95    | 0.004 ms        | 0.005 ms      | 0.014 ms (0009.html, 151 bytes)                    |
| owasp-java        | 286   | 0.006 ms        | 0.006 ms      | 0.030 ms (0271.html, 348 bytes)                    |
| payloads          | 1,047 | 0.005 ms        | 0.006 ms      | 0.057 ms (0709.html, 968 bytes)                    |
| portswigger       | 461   | 0.005 ms        | 0.006 ms      | 0.032 ms (0422.html, 281 bytes)                    |
| tinymce           | 11    | 0.012 ms        | 0.012 ms      | 0.024 ms (0001.html, 145 bytes)                    |
| wikipedia         | 6     | 6.95 ms         | 7.12 ms       | 14.1 ms (0006.html, 1.1 MB)                        |
| wordpress         | 113   | 0.052 ms        | 0.084 ms      | 0.65 ms (0072.html, 29 KB)                         |
| wpt               | 42    | 0.005 ms        | 0.006 ms      | 0.010 ms (0008.html, 55 bytes)                     |
| fixtures/accept   | 8     | 0.013 ms        | 0.025 ms      | 0.065 ms (html5-elements.html, 3 KB)               |
| fixtures/reject   | 10    | 0.009 ms        | 0.009 ms      | 0.014 ms (attribute-not-allowed-1.html, 235 bytes) |
| fixtures/tinymce4 | 2     | 0.13 ms         | 0.13 ms       | 0.15 ms (input.html, 2 KB)                         |

```
