"""운영 실행 진입점.

Railway 사설 네트워크는 환경에 따라 IPv6만 쓰거나 IPv4와 IPv6를 모두 쓴다. uvicorn에 host="::"만 주면
IPv6 전용 소켓이 되므로, IPv4와 IPv6를 함께 받는(dual-stack) 소켓을 직접 만들어 넘긴다.
IPv6를 쓸 수 없는 환경이면 0.0.0.0으로 대체한다.
"""

import os
import socket

import uvicorn


def create_socket(port: int) -> socket.socket:
    try:
        sock = socket.socket(socket.AF_INET6, socket.SOCK_STREAM)
        sock.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
        address = ("::", port)
    except OSError:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        address = ("0.0.0.0", port)

    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.bind(address)
    sock.set_inheritable(True)

    return sock


if __name__ == "__main__":
    port = int(os.getenv("PORT", "8001"))
    uvicorn.Server(uvicorn.Config("app.main:app", log_level="info")).run(sockets=[create_socket(port)])
