import concurrent.futures
import json
import os
import sys
import time
import urllib.request

TARGET_URL = os.getenv('TARGET_URL', 'http://php-app:8080/report-legacy?days=30&limit=20')
REQUESTS = int(os.getenv('REQUESTS', '30'))
CONCURRENCY = int(os.getenv('CONCURRENCY', '5'))
TIMEOUT = float(os.getenv('TIMEOUT_SECONDS', '10'))
TEST_NAME = os.getenv('TEST_NAME', 'unnamed-test')
OUTPUT_FILE = os.getenv('OUTPUT_FILE', '')


def hit(_):
    start = time.perf_counter()
    with urllib.request.urlopen(TARGET_URL, timeout=TIMEOUT) as response:
        body = response.read()
        status = getattr(response, 'status', 200)
    elapsed_ms = round((time.perf_counter() - start) * 1000, 2)
    return {
        'status': status,
        'elapsed_ms': elapsed_ms,
        'bytes': len(body),
    }


def percentile(values, percent):
    if not values:
        return 0.0
    ordered = sorted(values)
    index = max(0, min(len(ordered) - 1, int((percent / 100) * len(ordered) + 0.9999) - 1))
    return round(ordered[index], 2)


def main():
    print(f'Loadtest -> name={TEST_NAME} target={TARGET_URL} requests={REQUESTS} concurrency={CONCURRENCY}')
    start = time.perf_counter()
    results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=CONCURRENCY) as executor:
        for result in executor.map(hit, range(REQUESTS)):
            results.append(result)
    total_ms = round((time.perf_counter() - start) * 1000, 2)
    latencies = [item['elapsed_ms'] for item in results]
    summary = {
        'test_name': TEST_NAME,
        'target_url': TARGET_URL,
        'requests': len(results),
        'concurrency': CONCURRENCY,
        'total_time_ms': total_ms,
        'avg_ms': round(sum(latencies) / len(latencies), 2) if latencies else 0.0,
        'p95_ms': percentile(latencies, 95),
        'p99_ms': percentile(latencies, 99),
        'max_ms': max(latencies) if latencies else 0.0,
        'min_ms': min(latencies) if latencies else 0.0,
        'status_histogram': {
            '2xx': len([r for r in results if 200 <= r['status'] < 300]),
            '4xx': len([r for r in results if 400 <= r['status'] < 500]),
            '5xx': len([r for r in results if r['status'] >= 500]),
        },
    }
    text = json.dumps(summary, indent=2, ensure_ascii=False)
    print(text)
    if OUTPUT_FILE:
        with open(OUTPUT_FILE, 'w', encoding='utf-8') as fh:
            fh.write(text + '\n')


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print(json.dumps({'error': str(exc), 'target_url': TARGET_URL}, ensure_ascii=False), file=sys.stderr)
        sys.exit(1)
