import base64
import json
import unittest
from unittest.mock import patch

import server


class Response:
    def __init__(self, status, body):
        self.status_code = status
        self.content = body.encode("utf-8")
        self.text = body


class Cookies:
    def __init__(self):
        self.values = {}

    def set(self, name, value, **kwargs):
        self.values[name] = value


class FakeSession:
    def __init__(self, get_responses=None, post_responses=None):
        self.get_responses = list(get_responses or [])
        self.post_responses = list(post_responses or [])
        self.cookies = Cookies()
        self.posts = []

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def get(self, url, **kwargs):
        return self.get_responses.pop(0)

    def post(self, url, **kwargs):
        self.posts.append((url, kwargs))
        return self.post_responses.pop(0)


class SourcesTest(unittest.TestCase):
    def test_twoip_cookie_challenge_is_followed_once(self):
        script = 'const answer = "token_123"; const salt = "salt_abc";'
        challenge = '<script>var decoded=atob("' + base64.b64encode(script.encode()).decode() + '");</script>'
        session = FakeSession([Response(503, challenge), Response(200, '<table>8.8.8.8:8080</table>')])
        result = server.fetch_twoip(session)
        self.assertTrue(result["ok"])
        self.assertIn("8.8.8.8:8080", result["content"])
        self.assertEqual(session.cookies.values, {"2ip_js_challenge": "token_123", "2ip_js_challenge_salt": "salt_abc"})

    def test_unknown_challenge_is_not_treated_as_a_list(self):
        session = FakeSession([Response(503, "challenge without a recognised response")])
        self.assertFalse(server.fetch_twoip(session)["ok"])

    def test_twoip_can_retry_a_temporary_failure_in_a_fresh_session(self):
        first = FakeSession([Response(503, "unrecognised challenge")])
        second = FakeSession([Response(200, "<table>8.8.8.8:8080</table>")])
        with patch.object(server.requests, "Session", side_effect=[first, second]) as factory:
            self.assertTrue(server.fetch_source(server.fetch_twoip, attempts=2)["ok"])
        self.assertEqual(factory.call_count, 2)

    def test_advanced_rejects_a_successful_html_challenge(self):
        self.assertFalse(server.fetch_advanced(FakeSession([Response(200, "<html>challenge</html>")]))["ok"])

    def test_geonix_retains_protocols_and_partial_successes(self):
        session = FakeSession([Response(200, '{"isCaptchaActive": false}')], [
            Response(200, '["8.8.8.8:80"]'), Response(503, "bad"),
            Response(200, '["1.1.1.1:1080"]'), Response(200, '[]'),
        ])
        result = server.fetch_geonix(session)
        self.assertTrue(result["ok"])
        self.assertEqual(result["groups"]["HTTP"], ["8.8.8.8:80"])
        self.assertEqual(result["groups"]["SOCKS4"], ["1.1.1.1:1080"])
        self.assertEqual(result["errors"]["HTTPS"], "HTTP 503")
        self.assertEqual([post[1]["json"]["proxyProtocols"][0] for post in session.posts], list(server.PROTOCOLS))

    def test_geonix_captcha_is_never_bypassed(self):
        session = FakeSession([Response(200, json.dumps({"isCaptchaActive": True}))])
        self.assertFalse(server.fetch_geonix(session)["ok"])
        self.assertEqual(session.posts, [])


if __name__ == "__main__":
    unittest.main()
