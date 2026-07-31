#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <string.h>
#include <unistd.h>

int main(int argc, char **argv)
{
    if (argc != 2) {
        fprintf(stderr, "usage: fsync-dir <directory>\n");
        return 64;
    }

    int fd = open(argv[1], O_RDONLY | O_DIRECTORY);
    if (fd < 0) {
        fprintf(stderr, "open(%s): %s\n", argv[1], strerror(errno));
        return 1;
    }

    if (fsync(fd) != 0) {
        fprintf(stderr, "fsync(%s): %s\n", argv[1], strerror(errno));
        close(fd);
        return 1;
    }

    return close(fd) == 0 ? 0 : 1;
}
