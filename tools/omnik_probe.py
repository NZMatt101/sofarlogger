import socket, struct, sys
ip, sn = sys.argv[1], int(sys.argv[2])
body = bytes([0x02, 0x40, 0x30]) + struct.pack('<I', sn) * 2 + b'\x01\x00'
frame = b'\x68' + body + bytes([sum(body) & 0xFF, 0x16])
print('send', frame.hex())
s = socket.create_connection((ip, 8899), 5); s.settimeout(6); s.sendall(frame)
data = b''
try:
    while True:
        c = s.recv(1024)
        if not c: break
        data += c
        if len(data) > 2 and len(data) >= data[1] + 14: break
except socket.timeout: pass
s.close()
print(len(data), 'B', data.hex())
print('ascii:', ''.join(chr(b) if 32 <= b < 127 else '.' for b in data))
if len(data) > 77:
    h = lambda o, d: struct.unpack_from('>H', data, o)[0] / d
    print('id', data[15:31])
    print('temp', h(31, 10), 'vpv', [h(33 + 2*i, 10) for i in range(3)], 'ipv', [h(39 + 2*i, 10) for i in range(3)])
    print('iac', [h(45 + 2*i, 10) for i in range(3)], 'vac', [h(51 + 2*i, 10) for i in range(3)], 'fac', h(57, 100))
    print('pac', [h(59 + 4*i, 1) for i in range(3)], 'e_today', h(69, 100),
          'e_total', struct.unpack_from('>I', data, 71)[0] / 10, 'h_total', struct.unpack_from('>I', data, 75)[0])
