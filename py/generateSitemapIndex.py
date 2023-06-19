# Modified from MirahezeMagic

from datetime import datetime
import requests
import xmltodict
import os
import argparse
import boto3
from botocore.exceptions import NoCredentialsError
from time import sleep

parser = argparse.ArgumentParser(
    description='Generate WikiTide sitemap index of all public wikis, and upload the object to an S3 bucket')
parser.add_argument(
    '-B', '--bucket', dest='bucket', required=True,
    help='Name of the S3 bucket')
parser.add_argument(
    '-K', '--access-key', dest='access_key', default=os.environ.get('AWS_ACCESS_KEY_ID', None),
    help='Access key for AWS')
parser.add_argument(
    '-S', '--secret-key', dest='secret_key', default=os.environ.get('AWS_SECRET_ACCESS_KEY', None),
    help='Secret key for AWS')
args = parser.parse_args()

reqsession = requests.Session()
print('getting wikilist')
URL = 'https://meta.wikitide.com/w/api.php'
PARAMS = {
    'action': 'wikidiscover',
    'format': 'json',
    'wdstate': 'public',
    'wdsiteprop': 'dbname',
}
apirequest = reqsession.get(url=URL, params=PARAMS)
DATA = apirequest.json()
data = DATA['wikidiscover']
print(f'Got {len(data)} wikis, generating index!')
maps = []
rls = 0
count = 0
hits = 1
down = 1
for wikidata in data:
    count = count + 1
    if count == 10:
        print(f'Processed {count * hits} wikis')
        count = 0
        hits = hits + 1
    wiki = wikidata['dbname']
    urlreq = f'https://static.wikiforge.net/{wiki}/sitemaps/sitemap.xml'
    req = reqsession.get(url=urlreq)
    if req.status_code == 429:
        print(f'Rate Limited on {wiki} backing off for {1 + int(rls)} seconds')
        sleep(1 + int(rls))  # sleep 1 second for every time rate limited
        req = reqsession.get(url=urlreq)
        rls = rls + 1
    while req.status_code == 502 or req.status_code == 503:  # WikiForge might be down, pause for a bit
        secondswait = 3 * down
        down = down + 1
        print(f'Got 5xx error on {urlreq} - waiting for {secondswait} seconds')
        sleep(secondswait)
        req = reqsession.get(url=urlreq)
    try:
        smap = xmltodict.parse(req.content)
    except Exception as e:
        print(f'Caught exception {str(e)}, skipping "{urlreq}" - returned {req.status_code}')
        continue
    try:
        maps.append(smap['sitemapindex']['sitemap']['loc'])  # Single items are not lists
    except TypeError:
        smap = smap['sitemapindex']['sitemap']
        for info in smap:
            try:
                maps.append(info['loc'])
            except KeyError as e:
                print(f'Caught exception {str(e)}, "{urlreq}" may be partly ignored - got line {info}')  # Somehow missing the data we need, ignore
    except KeyError as e:
        print(f'Caught exception {str(e)} while parsing "{urlreq}"')  # Sitemap data is not actually saved in this sitemap! Ignore
    sleep(0.5)  # sleep half a second after each one

lines = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
for sitemap in maps:
    date = datetime.now()
    dt_string = date.strftime('%Y-%m-%dT%H:%M:%SZ')
    loc = f'\n\t\t<loc>{sitemap}</loc>'
    lastmod = f'\n\t\t<lastmod>{dt_string}</lastmod>'
    lines += f'\n\t<sitemap>{loc}{lastmod}\n\t</sitemap>'

lines += '\n</sitemapindex>'

s3 = boto3.client('s3', aws_access_key_id=args.access_key, aws_secret_access_key=args.secret_key)
try:
    s3.put_object(
        Bucket=args.bucket,
        Key='sitemap-wikitide.xml',
        Body=lines,
        ContentType='application/xml',
    )
    print('done')
except NoCredentialsError:
    print('AWS credentials not found. Please provide valid access key and secret key.')
